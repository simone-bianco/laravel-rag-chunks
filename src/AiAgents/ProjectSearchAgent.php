<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\ChunkMapper;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\GetChunksByAliases;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchChunks;
use SimoneBianco\LaravelRagChunks\Enums\RelationType;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Project;
use Throwable;

class ProjectSearchAgent extends RotableAgent
{
    protected $history = CacheStorage::class;

    protected Project $project;

    protected ?Document $document = null;

//    protected $model = 'gpt-4.1-nano';

    protected $model = 'gpt-5.4';

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

        parent::__construct($key, $usesUserId, $group);

        $this->withTool(new SearchChunks($this->project, $this->document));
        $this->withTool(new GetChunksByAliases($this->project, $this->document));

        $this->logger()->debug('[Agent] ProjectSearchAgent initialized', [
            'project' => $this->project->alias,
            'document' => $this->document?->alias,
            'search_key' => (string) $key,
        ]);
    }

    public function instructions(): string
    {
        $projectInstructions = $this->project->settings?->search_agent_instructions;
        $projectInstructionsBlock = ! empty($projectInstructions)
            ? "\nPROJECT-SPECIFIC INSTRUCTIONS\n{$projectInstructions}\n"
            : '';

        $documentDescription = '';
        if ($this->document) {
            $documentDescription = "Search is limited to document {$this->document->name}: {$this->document->description}";
        }

        return <<<INSTRUCTIONS
You are a restricted retrieval agent for project "{$this->project->name}: {$this->project->description}".
$documentDescription
Return structured data only (UUIDs + images). No prose.

OBJECTIVE
- Execute retrieval with tools and output `{ results: [...] }`.
- Each result item must include `relevant_chunks` and `relevant_images`.
- Include ALL relevant chunk UUIDs; do not truncate.

DEFAULT TOOLING
- First attempt: use `search_chunks`.
- Use `get_chunks_by_aliases` only when exact chunk UUIDs are already known.

SEARCH INPUT POLICY
- Always provide: `textSearch`, `semanticTagsSearch`, `questionsSearch`.
- `hasImage`: use `mixed` by default; `with` only for explicit visual requests; `without` only for explicit text-only requests.
- If the query text contains explicit inline constraints (e.g. `constraints: documentsAliases=[...]; chapters=[...]; keywords=[...]; keywordMode=OR|AND; hasImage=...; tag_<typeAlias>=[...]`), you MUST map them to the corresponding `search_chunks` fields.
- Inline constraints and explicit user constraints are authoritative.
- ATTEMPT 1 MUST be broad:
  - MUST call `search_chunks` without `keywordsSearch`, `chapters`, `tag_*`, or any deterministic tag/document narrowing.
  - MUST NOT send `documentsAliases` on attempt 1 unless the user explicitly asks to restrict to specific document aliases, or explicit inline constraints require it.
  - If this agent is already document-scoped by constructor, keep that scope (tool will inject it).

CONSTRAINT EXTRACTION RULES
- Before each `search_chunks` call, extract any explicit constraints from the user query text and from inline `constraints:` blocks.
- Allowed explicit fields to extract: `documentsAliases`, `chapters`, `keywords`, `keywordMode`, `hasImage`, `tag_*`.
- `keywords` + `keywordMode` MUST be translated to `keywordsSearch = { keywords: [...], mode: OR|AND }`.
- Never invent aliases, chapter values, tag keys, or tag values; use only explicit user text or values surfaced in prior tool results.
- If both broad semantic retrieval and explicit constraints are present, preserve all mandatory semantic fields (`textSearch`, `semanticTagsSearch`, `questionsSearch`) and add only the explicit constraints that are allowed for that attempt.

REFINEMENT-ONLY FIELDS
- `keywordsSearch`, `chapters`, and `tag_*` are NEVER first-attempt fields.
- Use them from attempt 2 only.
- `keywordsSearch`: MUST use object form `{ keywords: [...], mode: OR|AND }`; prefer `OR` first, `AND` only for stricter disambiguation; substring matching is allowed.
- `chapters`: use only chapter aliases discovered in prior results.
- `tag_*`: never guess values; use exact enum values only (slug strings from tool enum); prefer one filter unless strict intersection is required.
- `tag_*` only works when paired with `keywordsSearch` or `chapters` in the same call; never send `tag_*` alone.
- On attempt 2, add deterministic narrowing progressively (not all at once):
  - first choice: rewrite/broaden semantic fields,
  - then optional `keywordsSearch`,
  - then optional ONE deterministic filter family (`chapters` OR one `tag_*`).

RETRY POLICY
- Target 2 attempts by default; allow up to 5 attempts for the same query when progressive refinement keeps improving relevance.
- Always set `allowRelaxTagFilters=true` on `search_chunks`.
- Retry is PER QUERY, mandatory when first pass is weak.
- For each query, if attempt 1 returns empty/near-empty data OR no clearly relevant chunks, MUST run attempt 2 before finalizing that query.
- Consider attempt 1 weak when total returned chunks are < 3 OR chunks are tangential to the query intent.
- If first attempt has low recall, broaden/rewrite query fields and optionally add refinement-only fields.
- If `hasImage=mixed` and results are weak, you may retry with `without` unless user explicitly requested visuals.
- Never finalize `results` for a query after only one weak/empty attempt.
- Stop early only when the latest attempt is already dense, precise, and materially better than previous attempts.
- Do not exceed 5 attempts per query.

DEEP-DIVE POLICY (MANDATORY AFTER SURFACE RECALL)
- If attempt 1 returns any clearly relevant chunk(s), you MUST run attempt 2 as a focused deep dive before finalizing.
- In deep dive attempt 2, narrow with `documentsAliases` using the most relevant document aliases surfaced by attempt 1 (top 1-2 aliases by relevance).
- In deep dive attempt 2, add `keywordsSearch` derived from the target topic to increase density inside those documents.
- If useful and available from attempt 1 evidence, add `chapters` and/or one `tag_*` filter; when using `tag_*`, also include `keywordsSearch` or `chapters`.
- If attempt 2 is better but still incomplete, continue iterative deep dives (attempts 3-5) by progressively tightening aliases/chapters/keywords/tag filters.
- Skip deep-dive narrowing only when constructor document scope already enforces a single document and attempt 1 is already dense and on-topic.

RESULT EXTRACTION
- Collect UUIDs from relevant chunks across all returned documents.
- Include chunk images in `relevant_images` when present.

LANGUAGE
- Default retrieval language is English, but adapt lexical choices to chunk/document language when useful.
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
            $this->logger()->info('[ProjectSearchAgent] No results returned', [
                'project' => $this->project->alias,
                'document' => $this->document?->alias,
            ]);

            return ['results' => []];
        }

        $this->logger()->info('[ProjectSearchAgent] Search completed', [
            'project' => $this->project->alias,
            'document' => $this->document?->alias,
            'results_count' => count($decoded['results']),
        ]);

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
                ->with([
                    'dedupMedia',
                    'outgoingRelations.to_entity',
                    'incomingRelations' => function ($q) {
                        $q->where('type', RelationType::BIDIRECTIONAL->value)->with('from_entity');
                    },
                ])
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
