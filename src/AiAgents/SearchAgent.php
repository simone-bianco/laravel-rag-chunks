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
            $this->withTool((new SaveSearchesResultsTool($this->scopeProjectId))
                ->compactionThreshold($this->compactionThreshold)
                ->optimizationChunkThreshold($this->optimizationChunkThreshold)
                ->creatorAgentId($this->callingAgentId));
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

        $this->persistResolvedResultsDeterministically($resolved);

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

    private function persistResolvedResultsDeterministically(array $resolved): void
    {
        if (! $this->searchResultsActive()) {
            return;
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
            $this->logger()->info('[SearchAgent] Deterministic save skipped: identical unique chunk set already saved', [
                'scope_type' => $this->scope->type->value,
                'scope_alias' => $this->scope->alias,
                'project_id' => $this->scopeProjectId,
                'reason' => 'zero_diff_unique_chunks',
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

        $this->logger()->info('[SearchAgent] Deterministic history save completed', [
            'scope_type' => $this->scope->type->value,
            'scope_alias' => $this->scope->alias,
            'saved_count' => is_array($saveResult) ? (int) ($saveResult['saved_count'] ?? 0) : 0,
            'skipped_count' => is_array($saveResult) ? (int) ($saveResult['skipped_count'] ?? 0) : 0,
            'compacted_count' => is_array($saveResult) ? (int) ($saveResult['compacted_count'] ?? 0) : 0,
            'status' => is_array($saveResult) ? ($saveResult['status'] ?? null) : null,
            'saved_ids' => is_array($saveResult) ? ($saveResult['saved_ids'] ?? []) : [],
            'saved_with_notes' => is_array($saveResult) ? (int) ($saveResult['saved_with_notes_count'] ?? 0) : 0,
            'query_previews' => array_values(array_map(
                fn (array $s): string => mb_substr((string) ($s['query'] ?? ''), 0, 120),
                $searchResults,
            )),
            'chunk_counts' => array_values(array_map(
                fn (array $s): int => count($s['chunk_ids'] ?? []),
                $searchResults,
            )),
        ]);
    }

    /**
     * Check whether the unique chunk IDs from the current search results
     * are identical to the most recently saved SearchResult for this project.
     *
     * When true, the deterministic save is skipped to avoid wasted writes,
     * unnecessary compaction/optimization, and spurious summary regeneration.
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

        $lastSaved = SearchResult::query()
            ->where('project_id', $this->scopeProjectId)
            ->latest()
            ->first();

        if ($lastSaved === null) {
            return false;
        }

        $savedResults = is_array($lastSaved->results) ? $lastSaved->results : [];
        $savedChunkIds = $this->extractChunkIdsFromStoredResults($savedResults);

        sort($currentChunkIds);
        sort($savedChunkIds);

        return $currentChunkIds === $savedChunkIds;
    }

    /**
     * Extract chunk IDs from a stored results array (new documents format or legacy format).
     *
     * @param array<string, mixed> $results
     * @return array<int, string>
     */
    private function extractChunkIdsFromStoredResults(array $results): array
    {
        // New format: documents.{alias}.chunk_ids
        $documents = is_array($results['documents'] ?? null) ? $results['documents'] : [];
        if ($documents !== []) {
            $ids = [];
            foreach ($documents as $docStats) {
                if (! is_array($docStats)) {
                    continue;
                }
                $chunkIds = is_array($docStats['chunk_ids'] ?? null) ? $docStats['chunk_ids'] : [];
                foreach ($chunkIds as $chunkId) {
                    if (is_string($chunkId) && $chunkId !== '') {
                        $ids[] = $chunkId;
                    }
                }
            }

            return array_values(array_unique($ids));
        }

        // Legacy format
        $legacyIds = is_array($results['chunk_ids'] ?? null) ? $results['chunk_ids'] : [];
        return array_values(array_unique(array_filter(
            $legacyIds,
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        )));
    }
}
