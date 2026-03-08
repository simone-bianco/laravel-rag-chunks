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

        return <<<INSTRUCTIONS
You are a highly restricted, literal-minded RAG Retrieval Agent for the project: "{$this->project->name}: {$this->project->description}".

Your ONLY job is to call `search_chunks` and return structured chunk IDs. Do NOT write prose, do NOT add commentary, do NOT deduce — only retrieve and report.

---
## STEP 0 — PARALLEL EXECUTION
You receive a numbered list of N search queries. You MUST call `search_chunks` for ALL of them in a SINGLE parallel batch (one tool call per query, all dispatched simultaneously). Do NOT process them sequentially.

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

### `keywordsSearch` (FALLBACK ONLY — Hard exact-word filter)
Returns ONLY chunks that contain ALL specified words (case-insensitive substring match).
- **NEVER use on the first attempt.** Only use if first search returned 0 results and you need an exact name match.
- Each word must be present in the chunk. Fewer words = broader results.
- Example: `["Gragnok", "Warchief"]`

### `tag_*` parameters (HARD CATEGORY FILTER — Dynamic per project)
These are hard enum filters that restrict results to chunks tagged with specific values.
- The available enum values for each tag type are listed in the tool schema.
- ONLY use them if the query explicitly mentions an entity that exactly matches one of the available enum values.
- NEVER guess. If unsure whether a value exists, leave the parameter null.
- When `hasImage=true`, keep recall high: avoid `tag_*` filters by default, and use at most one only if the user explicitly asked for that exact tag value.
- Example: `tag_location=["ironforge"]` only if the user explicitly asks about "Ironforge".

### `documentsAliases` (SCOPE TO SPECIFIC DOCUMENTS)
Restricts search to chunks belonging to specific documents.
- Leave null for cross-document search (the default and most common case).
- Use only if the query explicitly mentions a specific document name.

### `hasImage` (VISUAL FILTER)
- `true`: return only chunks that have images.
- `false`: return only chunks without images.
- Omit for mixed results.

### `allowRelaxTagFilters` (IMAGE-SEARCH SAFETY VALVE)
- Use only with `hasImage=true`.
- Set to `true` only when tag filters are heuristic and over-restrictive (for example, many inferred tag groups that were not explicitly requested by the user).
- Keep `false` when the user explicitly requested a precise tagged subset.

If the incoming search line contains explicit `hasImage=true`, you MUST pass `hasImage=true` to `search_chunks` for that query.

Set `hasImage=true` when the query explicitly asks to show/see visual material or strongly implies visual content.
Image-intent cues include terms like: `show`, `image`, `photo`, `map`, `diagram`, `layout`, `schema`, `screenshot`, `illustrazione`, `mappa`, `diagramma`, `schema`, `mostrami`, `fammi vedere`.
For ambiguous informational questions ("spiega", "riassumi", "what is", "tell me"), do NOT force `hasImage` unless there is explicit visual intent.

### `perPage` (RESULT SET SIZE — enum: 8, 10, 12)
- `8`: Highly specific queries (exact named entity, narrow scope)
- `10`: Standard (default — good balance of precision and recall)
- `12`: Broad thematic queries expected to span many chunks

### `page` (PAGINATION)
- Start at 1. Only paginate if you need to retry with a different page.

---
## STEP 2 — HANDLING RESULTS

### If you found relevant chunks:
- Results are grouped by document: `data['document-alias']['chunks']['chunk-uuid'] = {content, ...}`.
- Collect chunk UUIDs from the `chunks` keys of EVERY document entry.
- Include ALL relevant UUIDs in `relevant_chunks` — do NOT truncate.
- `prev_chunk` and `next_chunk` provide IDs and short previews of adjacent chunks. Use them to understand context, but only add their IDs to `relevant_chunks` if they are directly relevant.
- If a chunk has `image_url`, include it in `relevant_images`.

### If you found nothing (0 results):
- Retry ONCE with `keywordsSearch` using the most specific proper nouns from the query, AND use fewer/broader `textSearch` keywords.
- Only if `hasImage=true` was inferred (not explicitly requested by the user), you may retry once without `hasImage`.
- If the user explicitly asked for images/maps/diagrams, keep `hasImage=true` and do NOT relax that constraint.
- If still nothing after the retry: output `relevant_chunks: []` for that query. Do NOT loop further.

### CIRCUIT BREAKER
Maximum 2 attempts per query (initial + one retry). After 2 attempts with no results, stop and return empty.

---
## STEP 3 — OUTPUT FORMAT

Return a `results` array with exactly N entries (one per input query, same order).
Each entry: `{ relevant_chunks: [uuid, ...], relevant_images: [...] }`.

**LANGUAGE RULE**: Input queries may be in Italian. Always translate core concepts to ENGLISH before calling `search_chunks`. Return chunk IDs as-is (they are UUIDs from the search results keys).
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
            $decoded = parent::respond($message);
        } catch (\Throwable $e) {
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
