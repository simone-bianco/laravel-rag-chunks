<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Support\Facades\Log;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Embedding;
use SimoneBianco\LaravelRagChunks\Models\SearchResult;

class SearchResultService
{
    public static function make(): self
    {
        return new self();
    }

    /**
     * Semantic lookup of recent SearchResults by query similarity.
     *
     * @return array<int, array{id: string, project_id: ?string, query: string, notes: ?string, summary: ?string, hits: int, results: array<string, mixed>}>
     */
    public function getRecent(string $query, int $count = 5, ?string $projectId = null): array
    {
        $vector = Embedding::embed($query);

        $results = SearchResult::query()
            ->when(is_string($projectId) && $projectId !== '', static fn ($builder) => $builder->where('project_id', $projectId))
            ->nearestNeighbors('embedding', $vector)
            ->limit(max(1, $count))
            ->get(['id', 'project_id', 'query', 'notes', 'summary', 'hits', 'results'])
            ->map(fn (SearchResult $row): array => [
                'id'         => (string) $row->id,
                'project_id' => is_string($row->project_id) ? $row->project_id : null,
                'query'      => (string) $row->query,
                'notes'      => is_string($row->notes) ? $row->notes : null,
                'summary'    => is_string($row->summary) && trim($row->summary) !== '' ? trim($row->summary) : null,
                'hits'       => (int) $row->hits,
                'results'    => $this->resultsForRow($row),
            ])
            ->all();

        if (is_string($projectId) && $projectId !== '') {
            $results = array_values(array_filter(array_map(
                fn (array $row): ?array => $this->sanitizeHistoryRowForProject($row, $projectId),
                $results,
            )));
        }

        Log::channel('search')->info('[SearchResultService] History lookup', [
            'query_count' => substr_count($query, 'query="'),
            'recent_count' => count($results),
            'history_hit' => count($results) > 0,
            'project_id' => $projectId,
            'recent_ids' => array_column($results, 'id'),
        ]);

        return $results;
    }

    /**
     * Persist a SearchResult with embedded query vector (global cache).
     */
    public function save(string $query, array $results, ?string $aiAgentId = null, ?string $projectId = null, ?string $notes = null): SearchResult
    {
        $vector = Embedding::embed($query);

        $searchResult = SearchResult::create([
            'ai_agent_id' => $aiAgentId,
            'project_id'  => $projectId,
            'query'       => $query,
            'notes'       => is_string($notes) && trim($notes) !== '' ? trim($notes) : null,
            'results'     => $results,
            'embedding'   => $vector,
        ]);

        Log::channel('search')->info('[SearchResultService] Search result saved', [
            'search_result_id' => (string) $searchResult->id,
            'ai_agent_id' => $aiAgentId,
            'project_id' => $projectId,
            'notes' => $searchResult->notes,
            'query_preview' => mb_substr($query, 0, 200),
            'chunks_count' => count($results['chunk_ids'] ?? []),
        ]);

        return $searchResult;
    }

    /**
     * Fetch full results for a set of SearchResult ids.
     *
     * @param  array<int, string>  $ids
     * @return array<int, array{id: string, project_id: ?string, query: string, notes: ?string, summary: ?string, hits: int, results: array<string, mixed>}>
     */
    public function getSearchResults(array $ids, ?string $projectId = null): array
    {
        if (empty($ids)) {
            return [];
        }

        $scopedQuery = static function () use ($ids, $projectId) {
            return SearchResult::query()
                ->whereIn('id', $ids)
                ->when(is_string($projectId) && $projectId !== '', static fn ($builder) => $builder->where('project_id', $projectId));
        };

        $scopedQuery()->increment('hits');

        $rows = $scopedQuery()
            ->get(['id', 'project_id', 'query', 'notes', 'summary', 'hits', 'results'])
            ->map(fn (SearchResult $row): array => [
                'id'         => (string) $row->id,
                'project_id' => is_string($row->project_id) ? $row->project_id : null,
                'query'      => (string) $row->query,
                'notes'      => is_string($row->notes) ? $row->notes : null,
                'summary'    => is_string($row->summary) && trim($row->summary) !== '' ? trim($row->summary) : null,
                'hits'       => (int) $row->hits,
                'results'    => $this->resultsForRow($row),
            ])
            ->all();

        if (is_string($projectId) && $projectId !== '') {
            $rows = array_map(fn (array $row): array => $this->sanitizeRowForProject($row, $projectId), $rows);
        }

        $rowsById = [];
        foreach ($rows as $row) {
            $rowId = (string) ($row['id'] ?? '');
            if ($rowId !== '') {
                $rowsById[$rowId] = $row;
            }
        }

        $results = [];
        foreach ($ids as $id) {
            if (isset($rowsById[$id])) {
                $results[] = $rowsById[$id];
            }
        }

        Log::channel('search')->info('[SearchResultService] Reuse lookup', [
            'requested_ids_count' => count($ids),
            'resolved_count' => count($results),
            'search_results_hit' => count($results) > 0,
            'project_id' => $projectId,
            'resolved_ids' => array_values(array_map(
                static fn (array $row): string => (string) ($row['id'] ?? ''),
                $results,
            )),
        ]);

        return $results;
    }

    /**
     * @param array{id: string, project_id: ?string, query: string, notes: ?string, summary: ?string, hits?: int, results: array<string, mixed>} $row
     * @return array{id: string, project_id: ?string, query: string, notes: ?string, summary: ?string, hits?: int, results: array<string, mixed>}
     */
    private function sanitizeRowForProject(array $row, string $projectId): array
    {
        $results = $row['results'];
        if (($results['is_summary'] ?? false) === true) {
            return $row;
        }

        $chunkIds = $this->extractChunkIds($results);

        if ($chunkIds === []) {
            return $row;
        }

        $allowedIds = Chunk::query()
            ->whereIn('id', $chunkIds)
            ->whereHas('document', static fn ($documentQuery) => $documentQuery->where('project_id', $projectId))
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $allowed = array_fill_keys($allowedIds, true);

        $results['chunk_ids'] = array_values(array_filter(
            is_array($results['chunk_ids'] ?? null) ? $results['chunk_ids'] : [],
            static fn (mixed $chunkId): bool => is_string($chunkId) && isset($allowed[$chunkId]),
        ));

        if (is_array($results['relevant_chunks'] ?? null)) {
            $results['relevant_chunks'] = array_filter(
                $results['relevant_chunks'],
                static fn (mixed $chunkId): bool => is_string($chunkId) && isset($allowed[$chunkId]),
                ARRAY_FILTER_USE_KEY,
            );
        }

        if ($results['chunk_ids'] === []) {
            $results['relevant_images'] = [];
        }

        $row['results'] = $results;

        return $row;
    }

    /**
     * @param array{id: string, project_id: ?string, query: string, notes: ?string, summary: ?string, hits?: int, results: array<string, mixed>} $row
     * @return array{id: string, project_id: ?string, query: string, notes: ?string, summary: ?string, hits?: int, results: array<string, mixed>}|null
     */
    private function sanitizeHistoryRowForProject(array $row, string $projectId): ?array
    {
        $hadChunks = $this->extractChunkIds($row['results']) !== [];
        $sanitized = $this->sanitizeRowForProject($row, $projectId);
        $hasChunksAfterSanitization = $this->extractChunkIds($sanitized['results']) !== [];

        if ($hadChunks && ! $hasChunksAfterSanitization) {
            return null;
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $results
     * @return array<int, string>
     */
    private function extractChunkIds(array $results): array
    {
        $chunkIds = is_array($results['chunk_ids'] ?? null) ? $results['chunk_ids'] : [];
        $relevantChunkIds = is_array($results['relevant_chunks'] ?? null) ? array_keys($results['relevant_chunks']) : [];

        return array_values(array_unique(array_filter(
            array_merge($chunkIds, $relevantChunkIds),
            static fn (mixed $chunkId): bool => is_string($chunkId) && $chunkId !== '',
        )));
    }

    /** @return array<string, mixed> */
    private function resultsForRow(SearchResult $row): array
    {
        $summary = is_string($row->summary) ? trim($row->summary) : '';
        if ($summary !== '') {
            $summaryId = (string) $row->id;

            return [
                'chunk_ids' => [$summaryId],
                'relevant_chunks' => [
                    $summaryId => [
                        'content' => $summary,
                        'chapter' => 'search-result-summary',
                        'document' => [
                            'alias' => 'search-result-memory',
                            'description' => (string) $row->query,
                        ],
                    ],
                ],
                'relevant_images' => [],
                'source_search_result_id' => (string) $row->id,
                'is_summary' => true,
            ];
        }

        return is_array($row->results) ? $row->results : [];
    }

    /**
     * Flush all saved search results (global).
     */
    public function flush(): int
    {
        return SearchResult::query()->delete();
    }
}
