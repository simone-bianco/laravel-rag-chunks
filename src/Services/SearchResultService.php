<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Support\Facades\Log;
use SimoneBianco\LaravelRagChunks\Models\Embedding;
use SimoneBianco\LaravelRagChunks\Models\SearchResult;

class SearchResultService
{
    public static function make(): self
    {
        return new self();
    }

    /**
     * Semantic lookup of recent SearchResults by query similarity (global cache).
     *
     * @return array<int, array{id: string, query: string, notes: ?string, results: array<string, mixed>}>
     */
    public function getRecent(string $query, int $count = 5): array
    {
        $vector = Embedding::embed($query);

        $results = SearchResult::query()
            ->nearestNeighbors('embedding', $vector)
            ->limit(max(1, $count))
            ->get(['id', 'query', 'notes', 'results'])
            ->map(static fn (SearchResult $row): array => [
                'id'      => (string) $row->id,
                'query'   => (string) $row->query,
                'notes'   => is_string($row->notes) ? $row->notes : null,
                'results' => is_array($row->results) ? $row->results : [],
            ])
            ->all();

        Log::channel('search')->info('[SearchResultService] History lookup', [
            'query_count' => substr_count($query, 'query="'),
            'recent_count' => count($results),
            'history_hit' => count($results) > 0,
            'recent_ids' => array_column($results, 'id'),
        ]);

        return $results;
    }

    /**
     * Persist a SearchResult with embedded query vector (global cache).
     */
    public function save(string $query, array $results, ?string $projectId = null, ?string $notes = null): SearchResult
    {
        $vector = Embedding::embed($query);

        $searchResult = SearchResult::create([
            'project_id'  => $projectId,
            'query'       => $query,
            'notes'       => is_string($notes) && trim($notes) !== '' ? trim($notes) : null,
            'results'     => $results,
            'embedding'   => $vector,
        ]);

        Log::channel('search')->info('[SearchResultService] Search result saved', [
            'search_result_id' => (string) $searchResult->id,
            'project_id' => $projectId,
            'notes' => $searchResult->notes,
            'query_preview' => mb_substr($query, 0, 200),
            'chunks_count' => count($results['chunk_ids'] ?? []),
        ]);

        return $searchResult;
    }

    /**
     * Fetch full results for a set of SearchResult ids (global cache).
     *
     * @param  array<int, string>  $ids
     * @return array<int, array{id: string, query: string, notes: ?string, results: array<string, mixed>}>
     */
    public function getSearchResults(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        SearchResult::query()
            ->whereIn('id', $ids)
            ->increment('hits');

        $rows = SearchResult::query()
            ->whereIn('id', $ids)
            ->get(['id', 'query', 'notes', 'results'])
            ->map(static fn (SearchResult $row): array => [
                'id'      => (string) $row->id,
                'query'   => (string) $row->query,
                'notes'   => is_string($row->notes) ? $row->notes : null,
                'results' => is_array($row->results) ? $row->results : [],
            ])
            ->all();

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
            'resolved_ids' => array_values(array_map(
                static fn (array $row): string => (string) ($row['id'] ?? ''),
                $results,
            )),
        ]);

        return $results;
    }

    /**
     * Flush all saved search results (global).
     */
    public function flush(): int
    {
        return SearchResult::query()->delete();
    }
}
