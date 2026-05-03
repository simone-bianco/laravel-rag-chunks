<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools\Memory;

use Illuminate\Support\Facades\Log;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Tool;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\Models\SearchResult;
use SimoneBianco\LaravelRagChunks\Services\SearchResultAutomationSettings;
use SimoneBianco\LaravelRagChunks\Services\SearchResultMemoryOptimizationService;
use SimoneBianco\LaravelRagChunks\Services\SearchResultService;

class GetSearchesResultsTool extends Tool
{
    protected int $optimizationChunkThreshold;

    protected int $optimizationHitThreshold;

    public function __construct(
        protected ?string $scopeProjectId = null,
        ?string $name = 'get_searches_results',
        ?string $description = 'Given the identifiers of N previous searches from this agent history, return the full results of those searches (same output shape as the search tool).',
    ) {
        $this->optimizationChunkThreshold = max(1, (int) config('rag_chunks.search_results.optimization_chunk_threshold', 12));
        $this->optimizationHitThreshold = max(0, (int) config('rag_chunks.search_results.optimization_hit_threshold', 5));

        parent::__construct($name, $description);
    }

    public function optimizationChunkThreshold(int $threshold): self
    {
        $this->optimizationChunkThreshold = max(1, $threshold);

        return $this;
    }

    public function optimizationHitThreshold(int $threshold): self
    {
        $this->optimizationHitThreshold = max(0, $threshold);

        return $this;
    }

    public function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    public function getProperties(): array
    {
        return [
            'searchResultIds' => [
                'type'        => 'array',
                'description' => 'Array of SearchResult IDs (UUIDs) to retrieve, taken from the HISTORY block injected in the instructions.',
                'items'       => [
                    'type'        => 'string',
                    'description' => 'SearchResult UUID.',
                ],
            ],
        ];
    }

    protected array $required = ['searchResultIds'];

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    protected function handle(array|DataModel $input): mixed
    {
        $data   = is_array($input) ? $input : $input->toArray();
        $schema = is_array($data) ? $data : [];

        $ids = $schema['searchResultIds'] ?? [];
        if (! is_array($ids) || empty($ids)) {
            $this->logger()->warning('[GetSearchesResultsTool] Empty lookup request');

            return ['error' => 'searchResultIds cannot be empty'];
        }

        $this->logger()->info('[GetSearchesResultsTool] Lookup requested', [
            'requested_count' => count($ids),
            'project_id' => $this->scopeProjectId,
        ]);

        $ids = array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? trim($v) : null,
            $ids,
        )));

        $stored = SearchResultService::make()->getSearchResults($ids, $this->scopeProjectId);

        $results = array_values(array_filter(array_map(
            static function (array $row): ?array {
                $result = is_array($row['results'] ?? null) ? $row['results'] : null;
                if ($result === null) {
                    return null;
                }

                $notes = $row['notes'] ?? null;
                if (is_string($notes) && trim($notes) !== '') {
                    $result['notes'] = trim($notes);
                }

                return $result;
            },
            $stored,
        )));

        $optimization = $this->optimizeHotMemories($stored);

        $this->logger()->info('[GetSearchesResultsTool] Lookup completed', [
            'requested_count' => count($ids),
            'resolved_count' => count($results),
            'project_id' => $this->scopeProjectId,
            'resolved_ids' => array_values(array_map(
                static fn (array $row): string => (string) ($row['id'] ?? ''),
                $stored,
            )),
            'optimization' => $optimization,
        ]);

        return [
            'results' => $results,
        ];
    }

    /**
     * Optimize after building the response so the current hit still receives
     * the original chunks/summary, while future hits see the optimized memory.
     *
     * @param array<int, array<string, mixed>> $stored
     * @return array<int, array<string, mixed>>
     */
    private function optimizeHotMemories(array $stored): array
    {
        if ($this->optimizationHitThreshold <= 0 || $stored === []) {
            return [];
        }

        $ids = array_values(array_filter(array_map(
            static fn (array $row): ?string => is_string($row['id'] ?? null) ? (string) $row['id'] : null,
            $stored,
        )));

        if ($ids === []) {
            return [];
        }

        /** @var array<int, SearchResult> $rows */
        $rows = SearchResult::query()
            ->whereIn('id', $ids)
            ->when(is_string($this->scopeProjectId) && $this->scopeProjectId !== '', fn ($query) => $query->where('project_id', $this->scopeProjectId))
            ->get()
            ->all();

        $results = [];

        foreach ($rows as $row) {
            $automationSettings = SearchResultAutomationSettings::forProjectId(
                is_string($row->project_id) ? $row->project_id : $this->scopeProjectId,
            );
            $hitThreshold = $automationSettings->optimizationHitThreshold($this->optimizationHitThreshold);

            $hits = (int) $row->hits;
            if ($hitThreshold <= 0 || $hits <= $hitThreshold) {
                continue;
            }

            $allowedOperations = $automationSettings->optimizationOperations();
            if ($allowedOperations === []) {
                $results[] = [
                    'id' => (string) $row->id,
                    'hits' => $hits,
                    'result' => ['action' => 'keep', 'reason' => 'automatic_operations_disabled'],
                ];

                continue;
            }

            try {
                $results[] = [
                    'id' => (string) $row->id,
                    'hits' => $hits,
                    'result' => app(SearchResultMemoryOptimizationService::class)->optimizeIfNeeded(
                        memory: $row,
                        chunkThreshold: $this->optimizationChunkThreshold,
                        forceSplit: false,
                        summarizeWhenUnderThreshold: false,
                        forceDecision: true,
                        allowedOperations: $allowedOperations,
                    ),
                ];
            } catch (\Throwable $exception) {
                $this->logger()->warning('[GetSearchesResultsTool] Hot memory optimization failed', [
                    'search_result_id' => (string) $row->id,
                    'hits' => $hits,
                    'threshold' => $hitThreshold,
                    'error' => $exception->getMessage(),
                    'error_class' => get_class($exception),
                ]);

                $results[] = [
                    'id' => (string) $row->id,
                    'hits' => $hits,
                    'result' => ['action' => 'keep', 'reason' => 'optimization_failed'],
                ];
            }
        }

        return $results;
    }
}
