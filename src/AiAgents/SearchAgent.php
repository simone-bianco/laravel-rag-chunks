<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Illuminate\Support\Facades\Log;
use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\Concerns\SearchAgent\BuildsInstructions;
use SimoneBianco\LaravelRagChunks\AiAgents\Concerns\SearchAgent\HasSearchResults;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\Memory\GetSearchesResultsTool;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\Memory\SaveSearchesResultsTool;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\Memory\UpdatePersistentMemoryTool;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchChunks;
use SimoneBianco\LaravelRagChunks\Enums\SearchDepth;
use SimoneBianco\LaravelRagChunks\Enums\SearchScopeType;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelAiAgents\Agents\RotableAgent;
use SimoneBianco\LaravelRagChunks\Models\Project;
use SimoneBianco\LaravelRagChunks\Models\SearchResult;
use SimoneBianco\LaravelRagChunks\Services\SearchAgentResultResolver;
use SimoneBianco\LaravelRagChunks\Services\SearchResultAutomationSettings;
use SimoneBianco\LaravelRagChunks\Services\SearchResultMemoryOptimizationService;

class SearchAgent extends RotableAgent
{
    use BuildsInstructions, HasSearchResults;

    protected $history = CacheStorage::class;

    protected $model = 'gpt-5.4-mini';

    protected $maxCompletionTokens = 16384;

    protected $parallelToolCalls = true;

    protected SearchScope $scope;

    protected bool $includeImages;

    protected SearchDepth $deep;

    protected bool $historyEnabled;
    protected ?bool $persistentMemoryEnabled;
    protected ?int $persistentMemoryMaxWords = null;
    protected float $compactionThreshold;
    protected int $optimizationChunkThreshold;
    protected ?string $callingAgentId = null;
    protected ?string $scopeProjectId = null;
    protected ?string $currentInput = null;
    protected array $recentSearchResults = [];

    protected function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    public function getPersistentMemoryKey(): string
    {
        return "{$this->scope->getKey()}:search";
    }

    public function persistentMemoryActive(): bool
    {
        return $this->persistentMemoryEnabled === true;
    }

    public function __construct(
        $key,
        SearchScope $scope,
        bool $includeImages = true,
        ?string $model = null,
        SearchDepth $deep = SearchDepth::Standard,
        bool $usesUserId = false,
        ?string $group = null,
        ?bool $historyEnabled = null,
        ?float $compactionThreshold = null,
        ?int $optimizationChunkThreshold = null,
        ?string $callingAgentId = null,
        ?bool $persistentMemoryEnabled = null,
        ?int $persistentMemoryMaxWords = null,
    ) {
        $this->scope         = $scope;
        $this->includeImages = $includeImages;
        $this->deep          = $deep;
        $this->callingAgentId = is_string($callingAgentId) && trim($callingAgentId) !== ''
            ? trim($callingAgentId)
            : null;
        $this->scopeProjectId = $this->resolveProjectIdFromScope();

        // Resolve automation settings with full hierarchy: passed param → project → global → hardcoded fallback.
        $settings = $this->scopeProjectId !== null
            ? SearchResultAutomationSettings::forProjectId($this->scopeProjectId)
            : SearchResultAutomationSettings::defaults();

        $this->historyEnabled = $historyEnabled ?? true;
        $this->persistentMemoryEnabled = $persistentMemoryEnabled
            ?? $settings->persistentMemoryEnabled(true);
        $this->persistentMemoryMaxWords = $persistentMemoryMaxWords
            ?? $settings->persistentMemoryMaxWords(500);
        $this->compactionThreshold = $compactionThreshold
            ?? $settings->autoMergeDistance(0.08);
        $this->optimizationChunkThreshold = $optimizationChunkThreshold
            ?? $settings->optimizationChunkThreshold(30);

        parent::__construct($key, $usesUserId, $group);

        if ($model !== null) {
            $this->model = $model;
        }

        $this->responseSchema = $this->buildResponseSchema();

        $this->withTool(new SearchChunks($this->scope));
//        $this->withTool(new GetChunksByAliases($this->scope));

        if ($this->searchResultsActive()) {
            $this->withTool((new GetSearchesResultsTool($this->scopeProjectId))
                ->optimizationChunkThreshold($this->optimizationChunkThreshold));
        }

        if ($this->persistentMemoryActive()) {
            $this->withTool(new UpdatePersistentMemoryTool($this->getPersistentMemoryKey(), $this->persistentMemoryMaxWords));
        }

        $this->logger()->debug('[Agent] SearchAgent initialized', [
            'scope_type'     => $this->scope->type->value,
            'scope_alias'    => $this->scope->alias,
            'include_images' => $this->includeImages,
            'deep'           => $this->deep->value,
            'search_key'     => (string) $key,
            'scope_project_id' => $this->scopeProjectId,
            'compaction_threshold' => $this->compactionThreshold,
            'optimization_chunk_threshold' => $this->optimizationChunkThreshold,
            'history_enabled' => $this->historyEnabled ? 'true' : 'false',
            'persistent_memory_enabled' => $this->persistentMemoryEnabled ? 'true' : 'false',
            'persistent_memory_max_words' => $this->persistentMemoryMaxWords,
            'calling_agent_id' => $this->callingAgentId,
            'instructions_chars' => strlen($this->instructions()),
        ]);
    }

    protected function resolveProjectTagsFromScope(): array
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

    protected function resolveProjectIdFromScope(): ?string
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

        // History discovery now happens via GetSearchesResultsTool — the LLM
        // queries for memories itself and receives them with full context
        // (is_complete flag, per-document stats, chunks). No server-side
        // injection needed.
        $this->recentSearchResults = [];

        $this->injectInstructionsForCurrentTurn();
        $decoded = parent::respond($message);

        if (! is_array($decoded) || empty($decoded['results'])) {
            $this->logger()->info('[SearchAgent] No results returned', [
                'scope_type'  => $this->scope->type->value,
                'scope_alias' => $this->scope->alias,
            ]);

            return ['results' => [], 'memory_results' => []];
        }

        $resolver = app(SearchAgentResultResolver::class);
        $resolved = $resolver->resolve($decoded['results'], $this->scopeProjectId);

        $storeMode = is_string($decoded['store_mode'] ?? null)
            ? trim($decoded['store_mode'])
            : 'as_is';
        $storeContext = is_string($decoded['store_context'] ?? null)
            ? trim($decoded['store_context'])
            : null;

        $this->persistResolvedResultsDeterministically($resolved, $storeMode, $storeContext);

        $this->logger()->info('[SearchAgent] Search completed', [
            'scope_type'    => $this->scope->type->value,
            'scope_alias'   => $this->scope->alias,
            'results_count' => count($decoded['results']),
            'memory_count'  => count($resolved['memory_results'] ?? []),
            'query_chunk_counts' => array_values(array_map(
                static fn (array $result): int => count($result['chunk_ids'] ?? []),
                $resolved['results'] ?? [],
            )),
        ]);

        return $resolved;
    }

    private function persistResolvedResultsDeterministically(array $resolved, string $storeMode = 'as_is', ?string $storeContext = null): void
    {
        if (! $this->searchResultsActive()) {
            return;
        }

        if ($storeMode === 'none') {
            $this->logger()->info('[SearchAgent] Deterministic save skipped: store_mode=none');
            return;
        }

        if (! in_array($storeMode, ['as_is', 'split'], true)) {
            $storeMode = 'as_is';
        }

        $results = is_array($resolved['results'] ?? null)
            ? $resolved['results']
            : [];

        if ($results === []) {
            return;
        }

        $searchResults = collect($results)
            ->map(function (mixed $result): ?array {
                if (! is_array($result)) {
                    return null;
                }

                $chunkIds = is_array($result['chunk_ids'] ?? null)
                    ? array_values(array_filter($result['chunk_ids'], static fn (mixed $id): bool => is_string($id) && trim($id) !== ''))
                    : [];

                if ($chunkIds === []) {
                    return null;
                }

                $query = is_string($result['query'] ?? null)
                    ? trim((string) $result['query'])
                    : '';

                if ($query === '') {
                    return null;
                }

                $notes = is_string($result['notes'] ?? null)
                    ? trim((string) $result['notes'])
                    : null;

                $relevantImages = is_array($result['relevant_images'] ?? null)
                    ? $result['relevant_images']
                    : [];

                return [
                    'query' => $query,
                    'notes' => $notes,
                    'chunk_ids' => $chunkIds,
                    'relevant_images' => $relevantImages,
                    'is_complete' => is_bool($result['is_complete'] ?? null) ? $result['is_complete'] : false,
                ];
            })
            ->filter()
            ->values()
            ->all();

        if ($searchResults === []) {
            return;
        }

        // Skip save when the unique chunk set is identical to what's already saved.
        // Prevents wasted DB writes, unnecessary compaction/optimization, and
        // spurious summary regeneration on history-reused runs.
        if ($this->isChunkSetAlreadySaved($searchResults)) {
            $this->logger()->info('[SearchAgent] Deterministic save skipped: identical chunk set already saved', [
                'scope_type' => $this->scope->type->value,
                'scope_alias' => $this->scope->alias,
                'project_id' => $this->scopeProjectId,
                'reason' => 'hash_match',
            ]);

            return;
        }

        $tool = (new SaveSearchesResultsTool($this->scopeProjectId))
            ->compactionThreshold($this->compactionThreshold)
            ->optimizationChunkThreshold($this->optimizationChunkThreshold)
            ->creatorAgentId($this->callingAgentId);

        $saveResult = $tool->execute([
            'searchResults' => $searchResults,
        ]);

        $savedCount = is_array($saveResult) ? (int) ($saveResult['saved_count'] ?? 0) : 0;
        $savedIds = is_array($saveResult) ? ($saveResult['saved_ids'] ?? []) : [];

        // Split mode: the LLM wants one composite memory split into focused ones.
        // We saved it as one, now trigger split optimization on it.
        $splitResult = null;
        $splitIds = [];
        if ($storeMode === 'split' && $savedCount === 1 && ! empty($savedIds)) {
            $savedId = (string) $savedIds[0];
            $savedMemory = SearchResult::query()->find($savedId);
            if ($savedMemory !== null) {
                $splitResult = $this->triggerSplitOnMemory($savedMemory, $storeContext);
                if (is_array($splitResult) && ! empty($splitResult['created_ids'] ?? [])) {
                    $splitIds = $splitResult['created_ids'];
                }
            }
        }

        $this->logger()->info('[SearchAgent] Deterministic save completed', [
            'scope_type' => $this->scope->type->value,
            'scope_alias' => $this->scope->alias,
            'store_mode' => $storeMode,
            'saved_count' => $savedCount,
            'saved_ids' => $savedIds,
            'split_created_count' => count($splitIds),
            'split_created_ids' => $splitIds,
            'status' => is_array($saveResult) ? ($saveResult['status'] ?? null) : null,
        ]);
    }

    /**
     * Trigger split optimization on a saved memory.
     *
     * @return array<string, mixed>|null
     */
    private function triggerSplitOnMemory(SearchResult $memory, ?string $storeContext): ?array
    {
        try {
            $automationSettings = $this->scopeProjectId !== null
                ? SearchResultAutomationSettings::forProjectId($this->scopeProjectId)
                : SearchResultAutomationSettings::defaults();

            return app(SearchResultMemoryOptimizationService::class)->optimizeIfNeeded(
                memory: $memory,
                chunkThreshold: 1,
                automationSettings: $automationSettings,
                forceSplit: true,
                forceDecision: true,
            );
        } catch (\Throwable $e) {
            $this->logger()->warning('[SearchAgent] Split optimization failed', [
                'error' => $e->getMessage(),
                'memory_id' => (string) $memory->id,
            ]);

            return null;
        }
    }

    /**
     * Check whether the unique chunk IDs from the current search results
     * are identical to an already-saved SearchResult (via hash column).
     *
     * @param array<int, array{chunk_ids?: array<int, string>}> $searchResults
     */
    private function isChunkSetAlreadySaved(array $searchResults): bool
    {
        if ($this->scopeProjectId === null) {
            return false;
        }

        $currentChunkIds = collect($searchResults)
            ->flatMap(fn (array $result): array => is_array($result['chunk_ids'] ?? null)
                ? $result['chunk_ids']
                : [])
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        if ($currentChunkIds === []) {
            return false;
        }

        sort($currentChunkIds);
        $hash = hash('sha256', implode(',', $currentChunkIds));

        return SearchResult::query()
            ->where('project_id', $this->scopeProjectId)
            ->where('hash', $hash)
            ->exists();
    }
}
