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

    protected ?string $callingAgentId = null;
    protected bool $historyEnabled;
    protected ?string $currentInput = null;
    protected ?string $historyProjectId = null;
    protected ?string $historyProjectAlias = null;
    protected array $scopeProjectTags = [];

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
        ?string $callingAgentId = null,
        bool $historyEnabled = false,
    ) {
        $this->scope         = $scope;
        $this->includeImages = $includeImages;
        $this->deep          = $deep;
        $this->callingAgentId = $callingAgentId;
        $this->historyEnabled = $historyEnabled;

        $projectContext = $this->resolveProjectContextFromScope();
        $this->historyProjectId = $projectContext['id'] ?? null;
        $this->historyProjectAlias = $projectContext['alias'] ?? null;
        $this->scopeProjectTags = $projectContext['tags'] ?? [];

        parent::__construct($key, $usesUserId, $group);

        if ($model !== null) {
            $this->model = $model;
        }

        $this->responseSchema = $this->buildResponseSchema();

        $this->withTool(new SearchChunks($this->scope));
        $this->withTool(new GetChunksByAliases($this->scope));

        if ($this->searchResultsActive() && $this->historyProjectId !== null) {
            $this->withTool(new GetSearchesResultsTool($this->callingAgentId, $this->historyProjectId));
            $this->withTool(new SaveSearchesResultsTool($this->callingAgentId, $this->historyProjectId));
        }

        $this->logger()->debug('[Agent] SearchAgent initialized', [
            'scope_type'     => $this->scope->type->value,
            'scope_alias'    => $this->scope->alias,
            'include_images' => $this->includeImages,
            'deep'           => $this->deep->value,
            'search_key'     => (string) $key,
            'history_project_id' => $this->historyProjectId,
            'history_project_alias' => $this->historyProjectAlias,
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

        $history = [];
        if ($this->searchResultsActive()) {
            $history = $this->getRecentSearchResults((string) $this->currentInput);

            $this->logger()->info('[SearchAgent] Recent search history lookup', [
                'scope_type' => $this->scope->type->value,
                'scope_alias' => $this->scope->alias,
                'calling_agent_id' => $this->callingAgentId,
                'history_enabled' => $this->historyEnabled,
                'history_project_id' => $this->historyProjectId,
                'history_project_alias' => $this->historyProjectAlias,
                'input_preview' => mb_substr((string) $this->currentInput, 0, 200),
                'recent_searches_count' => count($history),
                'search_results_hit' => count($history) > 0,
                'recent_search_ids' => array_values(array_map(
                    static fn (array $entry): string => (string) ($entry['id'] ?? ''),
                    $history,
                )),
                'recent_search_queries' => array_values(array_map(
                    static fn (array $entry): string => mb_substr((string) ($entry['query'] ?? ''), 0, 200),
                    $history,
                )),
            ]);

            if (! empty($history)) {
                $this->logger()->info('[SearchAgent] Search results found', [
                    'scope_type' => $this->scope->type->value,
                    'scope_alias' => $this->scope->alias,
                    'history_project_id' => $this->historyProjectId,
                    'history_project_alias' => $this->historyProjectAlias,
                    'hit_count' => count($history),
                    'hit_ids' => array_values(array_map(
                        static fn (array $entry): string => (string) ($entry['id'] ?? ''),
                        $history,
                    )),
                ]);
            }
        }

        $historyBlock = '';
        if (! empty($history)) {
            $historyList = collect($history)
                ->map(static fn (array $entry): string => "- id={$entry['id']} | query=\"{$entry['query']}\"")
                ->join("\n");

            $historyBlock = "\n\nHISTORY\n"
                . "Recent prior searches for the calling agent (semantically closest to the current input):\n"
                . $historyList . "\n"
                . "If one of these prior queries exactly matches what you are looking for, skip `search_chunks` entirely and call "
                . "`get_searches_results` with the relevant ids to reuse the prior result set. You can also combine: use `get_searches_results` "
                . "for the already-covered angle and `search_chunks` only for the missing angle. An empty prior result means no chunks were found for that query.";
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
- First attempt: use `search_chunks`
- Use `get_chunks_by_aliases` only when exact chunk UUIDs are already known

SEARCH INPUT POLICY
- Always provide: `textSearch`, `semanticTagsSearch`, `questionsSearch`
{$imagePolicy}
{$scopeTagsBlock}
- If the query text contains explicit inline constraints (e.g. `constraints: documentsAliases=[...]; chapters=[...]; keywords=[...]; keywordMode=OR|AND; hasImage=...; tag_<typeAlias>=[...]`), you MUST map them to the corresponding `search_chunks` fields
- Inline constraints and explicit user constraints are authoritative
- ATTEMPT 1 MUST be broad:
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
        return $this->scopeProjectTags;
    }

    private function resolveProjectContextFromScope(): ?array
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

        if (! $project) {
            return null;
        }

        return [
            'id' => (string) $project->id,
            'alias' => (string) $project->alias,
            'tags' => $project->getTagsSlugsKeyedByTypes(),
        ];
    }

    private function buildSearchRetryBlock(): string
    {
        return match ($this->deep) {
            SearchDepth::Shallow => <<<'SECTION'
SEARCH POLICY (SHALLOW MODE)
- Run exactly 1 attempt per query. No deep dive required.
- Stop after the first attempt unless the result is completely empty.
- If first attempt returns zero results, run one retry with broader/rewritten semantic fields only.
SECTION,

            SearchDepth::Standard => <<<'SECTION'
RETRY POLICY
- Target 2 attempts by default; allow up to 5 when progressive refinement keeps improving relevance.
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
- Minimum 3 attempts per query; up to 7 when progressive refinement keeps improving relevance.
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
- Do NOT save empty results, weak/tangential results, failed attempts, exploratory intermediate attempts, or duplicates of a reused history result.
- Save only final curated groups: each item must include `query` plus `chunk_ids`. The `query` is a concise reusable description of what those chunks answer.
- If images materially help the result, include them in `relevant_images` on the saved item.
- After saving, still return the required structured `{ results: [...] }` response.
SECTION;
    }

    private function resolveResults(array $results): array
    {
        return array_map(function (array $searchResult) {
            $chunkIds = $this->normalizeChunkIds($searchResult['relevant_chunks'] ?? []);

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
