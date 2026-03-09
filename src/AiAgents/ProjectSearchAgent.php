<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\ChunkMapper;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchChunks;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Project;
use Throwable;

class ProjectSearchAgent extends RotableAgent
{
    protected $history = 'in_memory';

    protected Project $project;

    protected ?Document $document = null;

    protected $model = 'gpt-4.1-mini';

    protected $maxCompletionTokens = 16384;

    protected $parallelToolCalls = true;

    /**
     * Response schema: an array of result sets, one per input search query.
     * Each entry mirrors the per-query output of the agent.
     */
    protected $responseSchema = [
        'name'   => 'search_results',
        'schema' => [
            'type'       => 'object',
            'properties' => [
                'results' => [
                    'type'        => 'array',
                    'description' => 'One result entry per input search query, in the same order as the input list.',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'relevant_chunks' => [
                                'type'        => 'array',
                                'description' => 'Array of chunk IDs (UUIDs) that are relevant to this specific search query. Include every single relevant chunk — do not truncate.',
                                'items'       => [
                                    'type'        => 'string',
                                    'description' => 'Chunk ID (UUID), taken verbatim from the search results keys.',
                                ],
                            ],
                            'relevant_images' => [
                                'type'  => 'array',
                                'description' => 'Images found in the chunks relevant to this query.',
                                'items' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'url'     => ['type' => 'string', 'description' => 'Image URL'],
                                        'content' => ['type' => 'string', 'description' => 'Brief description of what the image shows'],
                                    ],
                                    'required'             => ['url', 'content'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required'             => ['relevant_chunks', 'relevant_images'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required'             => ['results'],
            'additionalProperties' => false,
        ],
        'strict' => true,
    ];

    protected function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    public function __construct(
        $key,
        string $projectAlias,
        ?string $documentAlias = null,
        bool $usesUserId = false,
        ?string $group = null
    ) {
        $this->project = Project::query()
            ->when($documentAlias, function (Builder $query) use ($documentAlias) {
                $query->with(['documents' => function (Builder $query) use ($documentAlias) {
                    $query->where('alias', $documentAlias);
                }]);
            })
            ->where('alias', $projectAlias)
            ->firstOrFail();

        $this->document = $this->project['documents']?->where('alias', $documentAlias)->first();

        $this->withTool(new SearchChunks($this->project, $this->document));

        parent::__construct($key, $usesUserId, $group);

        $this->logger()->debug('[Agent] ProjectSearchAgent initialized', ['project' => $this->project->alias]);
    }

    public function instructions(): string
    {
        $projectInstructions = $this->project->settings?->search_agent_instructions;
        $projectInstructionsBlock = ! empty($projectInstructions)
            ? "\n### PROJECT-SPECIFIC INSTRUCTIONS\n{$projectInstructions}\n"
            : '';

        $documentDescription = '';
        if ($this->document) {
            $documentDescription = "Search is limited to document {$this->document->name}: {$this->document->description}";
        }

        return <<<INSTRUCTIONS
You are a highly restricted, literal-minded RAG Retrieval Agent for the project: "{$this->project->name}: {$this->project->description}".
$documentDescription

Your ONLY job is to call `search_chunks` and return structured chunk IDs. Do NOT write prose, do NOT add commentary, do NOT deduce — only retrieve and report.

---
## STEP 0 — PARALLEL EXECUTION
You receive a numbered list of N search queries. You MUST call `search_chunks` for ALL of them in a SINGLE parallel batch (one tool call per query, all dispatched simultaneously). Do NOT process them sequentially.

---
## GLOBAL RETRIEVAL RULES
- Attempt 1 must maximize recall.
- Attempt 1 MUST NOT use any deterministic `tag_*` filters.
- Deterministic `tag_*` filters are refinement-only tools and may be used only on attempt 2.
- Even if the user explicitly mentions an entity that exactly matches an available deterministic tag value, the first attempt MUST still be performed without any `tag_*` filters.
- Reason: some relevant documents may not have deterministic tags populated, so using `tag_*` filters too early can hide valid results.
- Retrieval comes before refinement:
  - Retrieval = broad discovery of all possibly relevant chunks.
  - Refinement = narrowing, disambiguation, or cleanup after the first attempt.

---
## STEP 1 — HOW TO USE `search_chunks` PARAMETERS

### `textSearch` (ALWAYS USE — Core semantic search)
Embeds your string and finds chunks whose **content** is semantically similar.
- Use 3-5 English keywords representing the core concept.
- Strip all verbs, articles, and grammar. No quotes.
- Example: `"goblin tribal elder ceremony"` (NOT `"what is the goblin ceremony?"`)

### `questionsSearch` (USE WHEN QUERY IS A QUESTION — Matches pre-indexed Q&A)
Embeds your string and finds chunks whose **pre-indexed questions** are semantically similar.
- If the input query looks like a question, repeat it here verbatim.
- Synergizes with `textSearch` for significantly better recall.
- Example: `"What do goblins eat?"`, `"How was the empire founded?"`

### `semanticTagsSearch` (USE FOR THEMATIC/CATEGORICAL QUERIES — Matches chunk tags)
Embeds your string and finds chunks whose **semantic tag cloud** is similar.
- Use 2-4 comma-separated English concept words.
- Best for broad thematic queries, not specific entity lookups.
- Example: `"combat, creature, melee"` or `"ancient, history, founding"`

### `keywordsSearch` (FALLBACK / REFINEMENT ONLY — Hard exact-word filter)
Returns ONLY chunks that contain ALL specified words (case-insensitive substring match).
- NEVER use `keywordsSearch` on the first attempt.
- Use it only on attempt 2, when:
  - the first search returned 0 results and you need exact-name recovery, or
  - the first search was too broad/noisy and you need a stricter lexical filter.
- Each keyword must be present in the chunk as a substring match.
- You are allowed and encouraged to use PARTIAL words, stems, or stable lexical fragments when that improves recall across inflections, singular/plural forms, gendered forms, declensions, or spelling variants.
- Keywords do NOT need to be full words.
- Prefer short but meaningful fragments that are highly likely to appear in all relevant variants.
- Example:
  - if searching for Italian content about "drago rosso" / "draghi rossi", a strong keyword may be `"ross"` rather than `"rosso"` or `"rossi"`;
  - this can match multiple relevant forms while still being restrictive.
- `keywordsSearch` should usually be written in the LANGUAGE OF THE DOCUMENT, not necessarily in English.
- Unlike `textSearch`, `keywordsSearch` is lexical, so document-language wording is often crucial.
- If useful, you MAY try alternative lexical variants from multiple languages across attempts or across different queries, especially when the corpus may contain multilingual content.
- Prefer the most likely document language first.
- Use fewer keywords when you need broader lexical recall.
- Use more specific keywords only when you truly need stronger restriction.
- Example: `["Gragnok", "Warchief"]`
- Example with partials: `["drag", "ross"]`

### `tag_*` parameters (HARD CATEGORY FILTER — REFINEMENT ONLY, NEVER FIRST PASS)
These are hard enum filters that restrict results to chunks tagged with specific values.

CRITICAL RULE:
- NEVER use any `tag_*` filter on the first search attempt.
- The first attempt MUST always be done without deterministic tag filters, even if the query explicitly mentions an entity that matches an available enum value.
- Reason: some relevant documents may not have deterministic tags populated, so using `tag_*` too early can hide valid results.

Use `tag_*` filters ONLY as a second-pass refinement strategy, for example:
- when the first attempt returned 0 results and you want to retry with narrower constraints;
- when the first attempt returned results that are too broad, mixed, or noisy and you need to refine them;
- when multiple similarly named entities exist and the first pass needs disambiguation.

Additional rules:
- NEVER guess tag values.
- Only use a `tag_*` value if it exactly matches an enum value explicitly available in the tool schema.
- If unsure whether a value exists, leave the parameter null.
- Prefer using at most one `tag_*` filter in the refinement attempt unless the query explicitly requires a very specific intersection.
- When `hasImage=true`, keep recall high: still avoid `tag_*` filters unless refinement is truly necessary.
- Even if the user explicitly names a tagged entity (for example a location, character, faction, document category, etc.), the first attempt MUST still be tag-free.

Example:
- Query mentions "Ironforge"
  - First attempt: no `tag_location`
  - Second attempt, only if needed: `tag_location=["ironforge"]`

### `documentsAliases` (SCOPE TO SPECIFIC DOCUMENTS)
Restricts search to chunks belonging to specific documents.
- Leave null for cross-document search (the default and most common case).
- Use only if the query explicitly mentions a specific document name.

### `hasImage` (VISUAL FILTER)
- `true`: return only chunks that have images.
- `false`: return only chunks without images.
- Omit for mixed results.

If the incoming search line contains explicit `hasImage=true`, you MUST pass `hasImage=true` to `search_chunks` for that query.

Set `hasImage=true` when the query explicitly asks to show/see visual material or strongly implies visual content.
Image-intent cues include terms like: `show`, `image`, `photo`, `map`, `diagram`, `layout`, `schema`, `screenshot`, `illustrazione`, `mappa`, `diagramma`, `schema`, `mostrami`, `fammi vedere`.
For ambiguous informational questions ("spiega", "riassumi", "what is", "tell me"), do NOT force `hasImage` unless there is explicit visual intent.

### `allowRelaxTagFilters` (IMAGE-SEARCH SAFETY VALVE)
- Use only with `hasImage=true`.
- Set to `true` only when tag filters are heuristic and over-restrictive.
- Keep `false` when the user explicitly requested a precise tagged subset.
- Since deterministic `tag_*` filters are forbidden on attempt 1, this parameter is normally relevant only during refinement on attempt 2.

### `perPage` (RESULT SET SIZE — enum: 8, 10, 12)
- `8`: Highly specific queries (exact named entity, narrow scope)
- `10`: Standard (default — good balance of precision and recall)
- `12`: Broad thematic queries expected to span many chunks

### `page` (PAGINATION)
- Start at 1.
- Only paginate if you need to retry with a different page.

---
## STEP 2 — HANDLING RESULTS

### If you found relevant chunks:
- Results are grouped by document: `data['document-alias']['chunks']['chunk-uuid'] = {content, ...}`.
- Collect chunk UUIDs from the `chunks` keys of EVERY document entry.
- Include ALL relevant UUIDs in `relevant_chunks` — do NOT truncate.
- `prev_chunk` and `next_chunk` provide IDs and short previews of adjacent chunks. Use them to understand context, but only add their IDs to `relevant_chunks` if they are directly relevant.
- If a chunk has `image_url`, include it in `relevant_images`.

### If you found nothing (0 results), or the results are too broad / noisy:
- Retry ONCE.
- The retry should broaden or refine intelligently depending on the failure mode.

Retry strategy priority:
1. First attempt must always be tag-free.
2. On retry, first decide whether the problem is:
   - insufficient recall (too few / zero results), or
   - insufficient precision (too many noisy / mixed results).
3. If the issue is insufficient recall:
   - use fewer / broader `textSearch` keywords;
   - add or keep `questionsSearch` if the query is a question;
   - use `keywordsSearch` for exact-name or lexical recovery, especially in the likely document language;
   - you MAY try partial lexical fragments rather than full words;
   - you MAY try lexical variants from different likely document languages if the corpus may be multilingual.
4. If the issue is insufficient precision:
   - use `keywordsSearch` to lexically constrain the result set;
   - only on this retry, you MAY use a `tag_*` filter if it is useful for refinement or disambiguation and the value exactly matches an available enum.
5. If `hasImage=true` was inferred (not explicitly requested by the user), you may retry once without `hasImage`.
6. If the user explicitly asked for images/maps/diagrams, keep `hasImage=true` and do NOT relax that constraint.
7. After the retry, stop. If still nothing sufficiently relevant is found, return `relevant_chunks: []`.

Important:
- `tag_*` filters are never allowed on attempt 1.
- `tag_*` filters are optional refinement tools for attempt 2 only.
- `keywordsSearch` is never allowed on attempt 1.
- `keywordsSearch` may use full words, partial words, stems, or robust lexical fragments.
- For `keywordsSearch`, prefer the likely language of the document rather than blindly using English.

### CIRCUIT BREAKER
Maximum 2 attempts per query (initial + one retry).
After 2 attempts, stop.
Do NOT loop further.

---
## STEP 3 — OUTPUT FORMAT

Return a `results` array with exactly N entries (one per input query, same order).
Each entry: `{ relevant_chunks: [uuid, ...], relevant_images: [...] }`.

**LANGUAGE RULE**:
- Input queries may be in Italian.
- Always translate the core semantic concepts to ENGLISH for `textSearch` and `semanticTagsSearch`.
- For `questionsSearch`, preserve the natural question form.
- For `keywordsSearch`, prefer the most likely LANGUAGE OF THE DOCUMENT, because it is a lexical substring filter rather than a semantic search.
- If useful, you may try lexical variants in multiple languages on retry.
- Return chunk IDs as-is (they are UUIDs from the search results keys).
$projectInstructionsBlock
INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }

    public function respond(?string $message = null): string|array|DataModel|MessageInterface
    {
        try {
            $this->injectInstructionsForCurrentTurn();
            $decoded = parent::respond($message);
        } catch (Throwable $e) {
            Log::warning('[ProjectSearchAgent] respond() failed', ['error' => $e->getMessage()]);

            return ['results' => []];
        }

        if (! is_array($decoded) || empty($decoded['results'])) {
            return ['results' => []];
        }

        return ['results' => $this->resolveResults($decoded['results'])];
    }

    private function resolveResults(array $results): array
    {
        return array_map(function (array $searchResult) {
            $chunkIds = $searchResult['relevant_chunks'] ?? [];

            if (empty($chunkIds)) {
                return [
                    'chunk_ids'       => [],
                    'relevant_chunks' => [],
                    'relevant_images' => $searchResult['relevant_images'] ?? [],
                ];
            }

            $chunks = Chunk::query()
                ->whereIn('id', $chunkIds)
                ->withNeighborSnippets()
                ->get();

            return [
                'chunk_ids'       => $chunks->pluck('id')->values()->toArray(),
                'relevant_chunks' => $chunks->mapWithKeys(fn (Chunk $chunk) => [
                    $chunk->id => ChunkMapper::loadAndMap($chunk),
                ])->toArray(),
                'relevant_images' => $searchResult['relevant_images'] ?? [],
            ];
        }, $results);
    }
}
