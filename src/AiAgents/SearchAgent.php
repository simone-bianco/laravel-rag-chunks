<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Illuminate\Support\Facades\Log;
use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\Concerns\HasSearchResults;
use SimoneBianco\LaravelRagChunks\AiAgents\Concerns\NormalizesChunkIds;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\ChunkMapper;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\GetChunksByAliases;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\Memory\GetSearchesResultsTool;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\Memory\SaveSearchesResultsTool;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchChunks;
use SimoneBianco\LaravelRagChunks\Enums\RelationType;
use SimoneBianco\LaravelRagChunks\Enums\SearchDepth;
use SimoneBianco\LaravelRagChunks\Enums\SearchScopeType;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelAiAgents\Agents\RotableAgent;
use SimoneBianco\LaravelRagChunks\Models\Project;
use SimoneBianco\LaravelRagChunks\Services\SearchResultService;
use Throwable;

class SearchAgent extends RotableAgent
{
    use NormalizesChunkIds, HasSearchResults;

    protected $history = CacheStorage::class;

    protected $model = 'gpt-5-mini';

    protected $maxCompletionTokens = 16384;

    protected $parallelToolCalls = true;

    protected SearchScope $scope;

    protected bool $includeImages;

    protected SearchDepth $deep;

    protected bool $historyEnabled;
    protected ?string $scopeProjectId = null;
    protected ?string $currentInput = null;
    protected array $recentSearchResults = [];

    protected function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    public function __construct(
        $key,
        SearchScope $scope,
        bool $includeImages = true,
        ?string $model = null,
        SearchDepth $deep = SearchDepth::Standard,
        bool $usesUserId = false,
        ?string $group = null,
        bool $historyEnabled = false,
    ) {
        $this->scope         = $scope;
        $this->includeImages = $includeImages;
        $this->deep          = $deep;
        $this->historyEnabled = $historyEnabled;
        $this->scopeProjectId = $this->resolveProjectIdFromScope();

        parent::__construct($key, $usesUserId, $group);

        if ($model !== null) {
            $this->model = $model;
        }

        $this->responseSchema = $this->buildResponseSchema();

        $this->withTool(new SearchChunks($this->scope));
        $this->withTool(new GetChunksByAliases($this->scope));

        if ($this->searchResultsActive()) {
            $this->withTool(new GetSearchesResultsTool());
            $this->withTool(new SaveSearchesResultsTool($this->scopeProjectId));
        }

        $this->logger()->debug('[Agent] SearchAgent initialized', [
            'scope_type'     => $this->scope->type->value,
            'scope_alias'    => $this->scope->alias,
            'include_images' => $this->includeImages,
            'deep'           => $this->deep->value,
            'search_key'     => (string) $key,
            'scope_project_id' => $this->scopeProjectId,
        ]);
    }

    private function buildResponseSchema(): array
    {
        $itemRequired = [];
        $itemProperties = [];
        if ($this->searchResultsActive()) {
            $itemProperties['query'] = [
                'type' => 'string',
                'description' => 'An explicative query that briefly summarizes the search and the data contained in the chunks (just one sentence)'
            ];

            $itemRequired[] = 'query';
        }

        $itemProperties['relevant_chunks'] = [
            'type'        => 'array',
            'description' => 'Array of chunk IDs (UUIDs) relevant to this query. Include every single relevant chunk — do not truncate.',
            'items'       => [
                'type'        => 'string',
                'description' => 'Chunk ID (UUID), taken verbatim from the search results keys.'
            ],
        ];
        $itemRequired[] = 'relevant_chunks';

        if ($this->includeImages) {
            $itemProperties['relevant_images'] = [
                'type'        => 'array',
                'description' => 'Images found in chunks relevant to this query.',
                'items'       => [
                    'type'                 => 'object',
                    'properties'           => [
                        'url'     => ['type' => 'string', 'description' => 'Image URL'],
                        'content' => ['type' => 'string', 'description' => 'Brief description of what the image shows'],
                    ],
                    'required'             => ['url', 'content'],
                    'additionalProperties' => false,
                ],
            ];
            $itemRequired[] = 'relevant_images';
        }

        return [
            'name'   => 'search_results',
            'schema' => [
                'type'       => 'object',
                'properties' => [
                    'results' => [
                        'type'        => 'array',
                        'description' => 'One result entry per input search query, in the same order as the input list.',
                        'items'       => [
                            'type'                 => 'object',
                            'properties'           => $itemProperties,
                            'required'             => $itemRequired,
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required'             => ['results'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    public function instructions(): string
    {
        $scopeBlock          = $this->buildScopeBlock();
        $imagePolicy         = $this->buildImagePolicy();
        $schemaRequirement   = $this->includeImages
            ? '- Each result item must include `relevant_chunks` and `relevant_images`.'
            : '- Each result item must include `relevant_chunks`.';
        $searchRetryBlock    = $this->buildSearchRetryBlock();
        $scopeTagsBlock      = $this->buildScopeTagsBlock();
        $searchResultsMemoryBlock = $this->buildSearchResultsMemoryBlock();

        $withImagesInstruct = $this->includeImages ? ' + images' : '';
        $includeImagesInstruct = $this->includeImages ? '- Include chunk images in `relevant_images` when present.' : '- Ignore image fields; do not include them in your response.';

        $history = $this->recentSearchResults;

        $historyBlock = '';
        if (! empty($history)) {
            $historyList = collect($history)
                ->map(static fn (array $entry): string => "- id={$entry['id']} | query=\"{$entry['query']}\"")
                ->join("\n");

            $historyBlock = "\n\nHISTORY\n"
                . "Recent prior searches from the global shared cache (semantically closest to the current input):\n"
                . $historyList . "\n"
                . "History-first is mandatory: if entries are available, call `get_searches_results` first with the best id(s) before any `search_chunks` call. "
                . "If the reused result is semantically close enough and fully answers the current request, stop there and skip `search_chunks` entirely. "
                . "Only run `search_chunks` when reused results are empty, incomplete, tangential, or you need complementary angles. "
                . "You can also combine: use `get_searches_results` for the already-covered angle and `search_chunks` only for the missing angle. "
                . "An empty prior result means no chunks were found for that query.";
        }

        return <<<INSTRUCTIONS
You are a restricted retrieval agent
{$scopeBlock}
Return structured data only (UUIDs{$withImagesInstruct}). No prose

OBJECTIVE
- Execute retrieval with tools and output `{ results: [...] }`
{$schemaRequirement}
- Include ALL relevant chunk UUIDs; do not truncate

DEFAULT TOOLING
- If HISTORY entries are present: first call `get_searches_results` (history-first).
- If HISTORY is empty or insufficient: use `search_chunks`.
- Use `get_chunks_by_aliases` only when exact chunk UUIDs are already known

SEARCH INPUT POLICY
- Always provide: `textSearch`, `semanticTagsSearch`, `questionsSearch`
{$imagePolicy}
{$scopeTagsBlock}
- If the query text contains explicit inline constraints (e.g. `constraints: documentsAliases=[...]; chapters=[...]; keywords=[...]; keywordMode=OR|AND; hasImage=...; tag_<typeAlias>=[...]`), you MUST map them to the corresponding `search_chunks` fields
- Inline constraints and explicit user constraints are authoritative
- When you choose `search_chunks`, ATTEMPT 1 MUST be broad:
  - MUST call `search_chunks` without `keywordsSearch`, `chapters`, `tag_*`, or any deterministic tag/document narrowing
  - MUST NOT send `documentsAliases` on attempt 1 unless the user explicitly asks to restrict to specific document aliases, or explicit inline constraints require it
  - If this agent is already document-scoped by constructor, keep that scope (tool will inject it)

CONSTRAINT EXTRACTION RULES
- Before each `search_chunks` call, extract any explicit constraints from the user query text and from inline `constraints:` blocks
- Allowed explicit fields to extract: `documentsAliases`, `chapters`, `keywords`, `keywordMode`, `hasImage`, `tag_*`
- `keywords` + `keywordMode` MUST be translated to `keywordsSearch = { keywords: [...], mode: OR|AND }`
- Never invent aliases, chapter values, tag keys, or tag values; use only explicit user text or values surfaced in prior tool results

REFINEMENT-ONLY FIELDS
- `keywordsSearch`, `chapters`, and `tag_*` are NEVER first-attempt fields
- Use them from attempt 2 only
- `keywordsSearch`: MUST use object form `{ keywords: [...], mode: OR|AND }`; prefer `OR` first, `AND` only for stricter disambiguation
- `chapters`: use only chapter aliases discovered in prior results
- `tag_*`: never guess values; use exact enum values only; prefer one filter unless strict intersection is required
- `tag_*` only works when paired with `keywordsSearch` or `chapters`; never send `tag_*` alone
- On attempt 2, add deterministic narrowing progressively:
  - first choice: rewrite/broaden semantic fields
  - then optional `keywordsSearch`
  - then optional ONE deterministic filter family (`chapters` OR one `tag_*`)

{$searchRetryBlock}

RESULT EXTRACTION
- Collect UUIDs from relevant chunks across all returned documents
{$includeImagesInstruct}

{$searchResultsMemoryBlock}

LANGUAGE
- Default retrieval language is English, but adapt lexical choices to chunk/document language when useful

CRITICAL RULES
- Return as many relevant chunks as possible; exclude only those completely alien to the search

$historyBlock
INSTRUCTIONS;
    }

    private function buildScopeBlock(): string
    {
        return match ($this->scope->type) {
            SearchScopeType::Project  => "Project in scope: {$this->scope->alias}.",
            SearchScopeType::Document => "Search is limited to document: {$this->scope->alias}.",
        };
    }

    private function buildImagePolicy(): string
    {
        if (! $this->includeImages) {
            return '- `hasImage`: always use `without`; this search excludes images.';
        }

        return '- `hasImage`: use `mixed` by default; `with` only for explicit visual requests; `without` only for explicit text-only requests.';
    }

    private function buildScopeTagsBlock(): string
    {
        $resolvedTags = $this->resolveProjectTagsFromScope();
        $tagsJson = json_encode($resolvedTags, JSON_UNESCAPED_SLASHES);

        if ($tagsJson === false) {
            $tagsJson = '{}';
        }

        return <<<TAGS
- `tags` is MANDATORY in every `search_chunks` call.
- Always pass `tags` exactly as this project tag map: {$tagsJson}
- If the map is empty, still pass `tags: {}`; never omit the field.
TAGS;
    }

    private function resolveProjectTagsFromScope(): array
    {
        $project = match ($this->scope->type) {
            SearchScopeType::Project => Project::query()
                ->where('alias', $this->scope->alias)
                ->first(),
            SearchScopeType::Document => Document::query()
                ->where('alias', $this->scope->alias)
                ->with('project')
                ->first()?->project,
        };

        return $project?->getTagsSlugsKeyedByTypes() ?? [];
    }

    private function resolveProjectIdFromScope(): ?string
    {
        $project = match ($this->scope->type) {
            SearchScopeType::Project => Project::query()
                ->where('alias', $this->scope->alias)
                ->first(),
            SearchScopeType::Document => Document::query()
                ->where('alias', $this->scope->alias)
                ->with('project')
                ->first()?->project,
        };

        return $project ? (string) $project->id : null;
    }

    private function buildSearchRetryBlock(): string
    {
        return match ($this->deep) {
            SearchDepth::Shallow => <<<'SECTION'
SEARCH POLICY (SHALLOW MODE)
- These attempt rules apply only when using `search_chunks`.
- Run exactly 1 `search_chunks` attempt per query. No deep dive required.
- Stop after the first `search_chunks` attempt unless the result is completely empty.
- If first `search_chunks` attempt returns zero results, run one retry with broader/rewritten semantic fields only.
SECTION,

            SearchDepth::Standard => <<<'SECTION'
RETRY POLICY
- These attempt/deep-dive rules apply only when using `search_chunks`.
- Target 2 `search_chunks` attempts by default; allow up to 5 when progressive refinement keeps improving relevance.
- Always set `allowRelaxTagFilters=true` on `search_chunks`.
- Retry is PER QUERY, mandatory when first pass is weak (< 3 chunks or tangential results).
- Never finalize a query after only one weak/empty attempt.
- Stop early only when the latest attempt is already dense and precise.

DEEP-DIVE POLICY (MANDATORY AFTER SURFACE RECALL)
- If attempt 1 returns any clearly relevant chunk(s), MUST run attempt 2 as a focused deep dive.
- In attempt 2, narrow with `documentsAliases` (top 1-2 from attempt 1) + `keywordsSearch`.
- If useful, add `chapters` and/or one `tag_*`; when using `tag_*`, also include `keywordsSearch` or `chapters`.
- Continue iterative deep dives (attempts 3-5) by progressively tightening filters.
- Skip deep-dive only when scope is already a single document and attempt 1 is dense and on-topic.
SECTION,

            SearchDepth::Deep => <<<'SECTION'
RETRY POLICY (DEEP MODE)
- These attempt/deep-dive rules apply only when using `search_chunks`.
- Minimum 3 `search_chunks` attempts per query; up to 7 when progressive refinement keeps improving relevance.
- Always set `allowRelaxTagFilters=true` on `search_chunks`.
- Never finalize after 1-2 weak attempts; continue until density is maximized or 7 attempts are exhausted.

DEEP-DIVE POLICY (ALWAYS ACTIVE)
- Attempt 1: broad — no deterministic filters.
- Attempt 2: narrow to top 1-2 document aliases from attempt 1 + `keywordsSearch`.
- Attempt 3: add `chapters` or one `tag_*` on top of attempt 2 scope.
- Attempts 4-7: progressively tighten filters, try alternate document aliases, vary keyword stems.
- After each attempt, merge new non-duplicate UUIDs into the result set; do not discard earlier findings.
SECTION,
        };
    }

    public function prompt($message)
    {
        return $message;
    }

    public function respond(null|array|string $message = null): string|array|DataModel|MessageInterface
    {
        $this->currentInput = match (true) {
            is_string($message) => $message,
            is_array($message)  => json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
            default             => null,
        };

        $this->recentSearchResults = [];
        if ($this->searchResultsActive()) {
            $this->recentSearchResults = $this->getRecentSearchResults((string) $this->currentInput);

            if (! empty($this->recentSearchResults)) {
                $this->logger()->info('[SearchAgent] History hit', [
                    'scope' => $this->scope->alias,
                    'hit_count' => count($this->recentSearchResults),
                    'ids' => array_column($this->recentSearchResults, 'id'),
                ]);
            }
        }

        try {
            $this->injectInstructionsForCurrentTurn();
            $decoded = parent::respond($message);
        } catch (Throwable $e) {
            Log::warning('[SearchAgent] respond() failed', ['error' => $e->getMessage()]);

            return ['results' => []];
        }

        if (! is_array($decoded) || empty($decoded['results'])) {
            $this->logger()->info('[SearchAgent] No results returned', [
                'scope_type'  => $this->scope->type->value,
                'scope_alias' => $this->scope->alias,
            ]);

            return ['results' => []];
        }

        $resolvedResults = $this->resolveResults($decoded['results']);

        // Negative caching: persist empty results so future lookups know this query yielded nothing
        if ($this->searchResultsActive()) {
            foreach ($resolvedResults as $result) {
                $query = (string) ($result['query'] ?? '');
                if ($query === '' || ! empty($result['chunk_ids'])) {
                    continue;
                }

                if ($this->scopeProjectId === null) {
                    $this->logger()->warning('[SearchAgent] Skipping empty search save: missing project context', [
                        'scope_alias' => $this->scope->alias,
                        'query_preview' => mb_substr($query, 0, 100),
                    ]);

                    continue;
                }

                SearchResultService::make()->save($query, [
                    'chunk_ids' => [],
                    'relevant_chunks' => [],
                    'relevant_images' => [],
                ], $this->scopeProjectId, 'Deeply searched in the project, no relevant chunks found');

                $this->logger()->info('[SearchAgent] Saved empty search result', [
                    'scope_alias' => $this->scope->alias,
                    'query_preview' => mb_substr($query, 0, 100),
                ]);
            }
        }

        $this->logger()->info('[SearchAgent] Search completed', [
            'scope_type'    => $this->scope->type->value,
            'scope_alias'   => $this->scope->alias,
            'results_count' => count($decoded['results']),
            'query_chunk_counts' => array_values(array_map(
                static fn (array $result): int => count($result['chunk_ids'] ?? []),
                $resolvedResults,
            )),
            'query_chunk_ids_sample' => array_values(array_map(
                static fn (array $result): array => array_slice($result['chunk_ids'] ?? [], 0, 10),
                $resolvedResults,
            )),
        ]);

        return ['results' => $resolvedResults];
    }

    private function buildSearchResultsMemoryBlock(): string
    {
        if (! $this->searchResultsActive()) {
            return '';
        }

        return <<<'SECTION'
SEARCH-RESULT MEMORY
- `save_searches_results` is the ONLY way to persist search results. Do not assume results are saved automatically.
- Call `save_searches_results` exactly once, at the end of the whole search workflow, and only if the final retrieval was fruitful.
- A fruitful retrieval has at least one on-topic chunk that should be reusable for a future similar query.
- Do NOT save weak/tangential results, failed attempts, exploratory intermediate attempts, or duplicates of a reused history result.
- You MAY save a deeply investigated empty outcome (`chunk_ids: []`) only when it is clearly useful to avoid repeating the same search. In that case set `notes` with a clear reason (example: "Deeply searched in the project, no relevant chunks found").
- Save only final curated groups: each item must include `query` plus `chunk_ids`. The `query` is a concise reusable description of what those chunks answer.
- If images materially help the result, include them in `relevant_images` on the saved item.
- After saving, still return the required structured `{ results: [...] }` response.
SECTION;
    }

    private function resolveResults(array $results): array
    {
        return array_map(function (array $searchResult) {
            $chunkIds = $this->normalizeChunkIds($searchResult['relevant_chunks'] ?? []);
            $query = is_string($searchResult['query'] ?? null) ? trim((string) $searchResult['query']) : '';
            $notes = is_string($searchResult['notes'] ?? null) ? trim((string) $searchResult['notes']) : null;

            if (empty($chunkIds)) {
                return [
                    'query' => $query,
                    'notes' => $notes,
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
                ->get()
                ->unique('id')
                ->values();

            return [
                'query' => $query,
                'notes' => $notes,
                'chunk_ids'       => $chunks->pluck('id')->values()->toArray(),
                'relevant_chunks' => $chunks->mapWithKeys(fn (Chunk $chunk) => [
                    $chunk->id => ChunkMapper::loadAndMap($chunk),
                ])->toArray(),
                'relevant_images' => $searchResult['relevant_images'] ?? [],
            ];
        }, $results);
    }
}
