<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Illuminate\Support\Facades\Log;
use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\Concerns\BuildsInstructions;
use SimoneBianco\LaravelRagChunks\AiAgents\Concerns\HasSearchResults;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\GetChunksByAliases;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\Memory\GetSearchesResultsTool;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\Memory\SaveSearchesResultsTool;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchChunks;
use SimoneBianco\LaravelRagChunks\Enums\SearchDepth;
use SimoneBianco\LaravelRagChunks\Enums\SearchScopeType;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelAiAgents\Agents\RotableAgent;
use SimoneBianco\LaravelRagChunks\Models\Project;
use SimoneBianco\LaravelRagChunks\Services\SearchAgentResultResolver;
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
    protected float $compactionThreshold = 0.08;
    protected int $optimizationChunkThreshold = 30;
    protected int $optimizationHitThreshold = 5;
    protected ?string $callingAgentId = null;
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
        float $compactionThreshold = 0.08,
        int $optimizationChunkThreshold = 30,
        int $optimizationHitThreshold = 5,
        ?string $callingAgentId = null,
    ) {
        $this->scope         = $scope;
        $this->includeImages = $includeImages;
        $this->deep          = $deep;
        $this->historyEnabled = $historyEnabled;
        $this->compactionThreshold = max(0.0, min(1.0, $compactionThreshold));
        $this->optimizationChunkThreshold = max(1, $optimizationChunkThreshold);
        $this->optimizationHitThreshold = max(0, $optimizationHitThreshold);
        $this->callingAgentId = is_string($callingAgentId) && trim($callingAgentId) !== ''
            ? trim($callingAgentId)
            : null;
        $this->scopeProjectId = $this->resolveProjectIdFromScope();

        parent::__construct($key, $usesUserId, $group);

        if ($model !== null) {
            $this->model = $model;
        }

        $this->responseSchema = $this->buildResponseSchema();

        $this->withTool(new SearchChunks($this->scope));
//        $this->withTool(new GetChunksByAliases($this->scope));

        if ($this->searchResultsActive()) {
            $this->withTool((new GetSearchesResultsTool($this->scopeProjectId))
                ->optimizationChunkThreshold($this->optimizationChunkThreshold)
                ->optimizationHitThreshold($this->optimizationHitThreshold));
            $this->withTool((new SaveSearchesResultsTool($this->scopeProjectId))
                ->compactionThreshold($this->compactionThreshold)
                ->optimizationChunkThreshold($this->optimizationChunkThreshold)
                ->creatorAgentId($this->callingAgentId));
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
            'optimization_hit_threshold' => $this->optimizationHitThreshold,
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
            'status' => is_array($saveResult) ? ($saveResult['status'] ?? null) : null,
        ]);
    }
}
