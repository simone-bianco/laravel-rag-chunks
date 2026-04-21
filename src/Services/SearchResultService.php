<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Support\Facades\Log;
use SimoneBianco\LaravelAiAgents\Models\AiAgent;
use SimoneBianco\LaravelRagChunks\Models\Embedding;
use SimoneBianco\LaravelRagChunks\Models\SearchResult;

class SearchResultService
{
    public function __construct(
        protected readonly AiAgent $agent,
    ) {
    }

    public static function forAgent(AiAgent $agent): self
    {
        return new self($agent);
    }

    /**
     * Semantic lookup of recent SearchResults for this agent by query similarity.
     *
     * @return array<int, array{id: string, query: string, results: array<string, mixed>}>
     */
    public function getRecent(string $query, string $projectId, int $count = 5): array
    {
        $vector = Embedding::embed($query);

        $results = SearchResult::query()
            ->where('ai_agent_id', $this->agent->id)
            ->where('project_id', $projectId)
            ->nearestNeighbors('embedding', $vector)
            ->limit(max(1, $count))
            ->get(['id', 'query', 'results'])
            ->map(static fn (SearchResult $row): array => [
                'id'      => (string) $row->id,
                'query'   => (string) $row->query,
                'results' => is_array($row->results) ? $row->results : [],
            ])
            ->all();

        Log::channel('search')->info('[SearchResultService] History lookup', [
            'agent_id' => (string) $this->agent->id,
            'project_id' => $projectId,
            'query_preview' => mb_substr($query, 0, 200),
            'recent_count' => count($results),
            'history_hit' => count($results) > 0,
            'recent_ids' => array_values(array_map(
                static fn (array $row): string => (string) ($row['id'] ?? ''),
                $results,
            )),
        ]);

        return $results;
    }

    /**
     * Persist a SearchResult with embedded query vector.
     */
    public function save(string $query, array $results, string $projectId): SearchResult
    {
        $vector = Embedding::embed($query);

        return SearchResult::create([
            'ai_agent_id' => $this->agent->id,
            'project_id'  => $projectId,
            'query'       => $query,
            'results'     => $results,
            'embedding'   => $vector,
        ]);
    }

    /**
     * Fetch full results for a set of SearchResult ids belonging to this agent.
     *
     * @param  array<int, string>  $ids
     * @return array<int, array{id: string, query: string, results: array<string, mixed>}>
     */
    public function getSearchResults(array $ids, string $projectId): array
    {
        if (empty($ids)) {
            return [];
        }

        SearchResult::query()
            ->where('ai_agent_id', $this->agent->id)
            ->where('project_id', $projectId)
            ->whereIn('id', $ids)
            ->increment('hits');

        $rows = SearchResult::query()
            ->where('ai_agent_id', $this->agent->id)
            ->where('project_id', $projectId)
            ->whereIn('id', $ids)
            ->get(['id', 'query', 'results'])
            ->map(static fn (SearchResult $row): array => [
                'id'      => (string) $row->id,
                'query'   => (string) $row->query,
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
            'agent_id' => (string) $this->agent->id,
            'project_id' => $projectId,
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
}
