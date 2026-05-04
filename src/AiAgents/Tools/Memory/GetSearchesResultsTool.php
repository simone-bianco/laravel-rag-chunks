<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools\Memory;

use Illuminate\Support\Facades\Log;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Tool;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\Models\Embedding;
use SimoneBianco\LaravelRagChunks\Models\SearchResult;

class GetSearchesResultsTool extends Tool
{
    protected int $optimizationChunkThreshold;

    public function __construct(
        protected ?string $scopeProjectId = null,
        ?string $name = 'get_searches_results',
        ?string $description = 'Discover relevant memories by query. Returns full results with chunks, per-document coverage stats, and is_complete flag.',
    ) {
        $this->optimizationChunkThreshold = max(1, (int) config('rag_chunks.search_results.optimization_chunk_threshold', 12));

        parent::__construct($name, $description);
    }

    public function optimizationChunkThreshold(int $threshold): self
    {
        $this->optimizationChunkThreshold = max(1, $threshold);

        return $this;
    }

    public function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    public function getProperties(): array
    {
        return [
            'queries' => [
                'type'        => 'array',
                'description' => 'One or more query strings. The tool finds the best matching memories for each and returns their full results.',
                'items'       => [
                    'type'        => 'string',
                    'description' => 'A query string to find matching memories.',
                ],
            ],
        ];
    }

    protected array $required = ['queries'];

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    protected function handle(array|DataModel $input): mixed
    {
        $data   = is_array($input) ? $input : $input->toArray();
        $schema = is_array($data) ? $data : [];

        $queries = $schema['queries'] ?? [];
        if (! is_array($queries) || empty($queries)) {
            $this->logger()->warning('[GetSearchesResultsTool] Empty query request');

            return ['error' => 'queries cannot be empty'];
        }

        $queries = array_values(array_filter(array_map(
            static fn ($v): ?string => is_string($v) ? trim($v) : null,
            $queries,
        )));

        if ($queries === []) {
            return ['error' => 'No valid queries provided'];
        }

        $this->logger()->info('[GetSearchesResultsTool] Query-based lookup requested', [
            'query_count' => count($queries),
            'project_id' => $this->scopeProjectId,
            'query_previews' => array_map(fn (string $q): string => mb_substr($q, 0, 120), $queries),
        ]);

        $allResults = [];
        $seenIds = [];

        foreach ($queries as $query) {
            $matches = $this->findMatchingMemories($query);

            foreach ($matches as $match) {
                $id = (string) ($match['id'] ?? '');
                if ($id === '' || isset($seenIds[$id])) {
                    continue;
                }

                $seenIds[$id] = true;
                $allResults[] = $match;
            }
        }

        $results = array_values(array_map(
            function (array $row): array {
                return $this->enrichMemoryResult($row);
            },
            $allResults,
        ));

        $this->logger()->info('[GetSearchesResultsTool] Query-based lookup completed', [
            'query_count' => count($queries),
            'found_count' => count($results),
            'project_id' => $this->scopeProjectId,
            'found_ids' => array_values(array_map(
                static fn (array $row): string => (string) ($row['id'] ?? ''),
                $results,
            )),
        ]);

        return [
            'results' => $results,
        ];
    }

    /**
     * Find memories matching a query via pgvector similarity.
     *
     * @return array<int, array{id: string, query: string, notes: ?string, summary: ?string, is_complete: bool, results: array, similarity: float}>
     */
    private function findMatchingMemories(string $query): array
    {
        $vector = Embedding::embed($query);
        $vectorString = '[' . implode(',', $vector) . ']';
        $scopeProjectId = $this->scopeProjectId;

        /** @var \Illuminate\Database\Eloquent\Collection<int, SearchResult> $similar */
        $similar = SearchResult::query()
            ->when(is_string($scopeProjectId) && $scopeProjectId !== '', static fn ($builder) => $builder->where('project_id', $scopeProjectId))
            ->nearestNeighbors('embedding', $vector)
            ->select(['id', 'project_id', 'query', 'notes', 'summary', 'is_complete', 'hits', 'results'])
            ->addSelect(\Illuminate\Support\Facades\DB::raw("1.0 - (embedding <=> '{$vectorString}') as similarity"))
            ->limit(5)
            ->get();

        $results = [];
        foreach ($similar as $row) {
            $results[] = [
                'id'         => (string) $row->id,
                'project_id' => is_string($row->project_id) ? $row->project_id : null,
                'query'      => (string) $row->query,
                'notes'      => is_string($row->notes) ? $row->notes : null,
                'summary'    => is_string($row->summary) && trim($row->summary) !== '' ? trim($row->summary) : null,
                'is_complete' => (bool) $row->is_complete,
                'hits'       => (int) $row->hits,
                'similarity' => (float) ($row->similarity ?? 0.0),
                'results'    => is_array($row->results) ? $row->results : [],
            ];
        }

        // Increment hits for returned memories
        if ($similar->isNotEmpty()) {
            SearchResult::query()
                ->whereIn('id', $similar->pluck('id')->all())
                ->increment('hits');
        }

        return $results;
    }

    /**
     * Enrich a raw memory row with document stats and summary.
     * NEVER loads chunk content from DB — the LLM uses documents.chunk_ids
     * to decide coverage, and calls search_chunks separately if it needs
     * actual chunk text for specific documents.
     *
     * @param array{id: string, query: string, notes: ?string, summary: ?string, is_complete: bool, results: array, similarity: float} $row
     * @return array<string, mixed>
     */
    private function enrichMemoryResult(array $row): array
    {
        $results = $row['results'];
        $hasSummary = isset($row['summary']) && $row['summary'] !== null && $row['summary'] !== '';
        $documents = is_array($results['documents'] ?? null) ? $results['documents'] : [];

        // Count total chunk IDs from documents
        $totalChunks = 0;
        foreach ($documents as $docStats) {
            if (is_array($docStats) && is_array($docStats['chunk_ids'] ?? null)) {
                $totalChunks += count($docStats['chunk_ids']);
            }
        }

        $enriched = [
            'id' => $row['id'],
            'query' => $row['query'],
            'notes' => $row['notes'],
            'is_complete' => $row['is_complete'],
            'similarity' => round($row['similarity'], 4),
            'chunks_count' => $totalChunks,
            'documents' => $documents,
        ];

        if ($hasSummary || ($results['is_summary'] ?? false)) {
            $enriched['summary'] = $row['summary'] ?? null;
        }

        // Legacy: if old-format memory has relevant_chunks but no documents,
        // surface the chunk_ids so the LLM can still reason about coverage.
        if ($documents === [] && $enriched['chunks_count'] === 0) {
            $legacyChunkIds = is_array($results['chunk_ids'] ?? null) ? $results['chunk_ids'] : [];
            if ($legacyChunkIds !== []) {
                $enriched['chunks_count'] = count($legacyChunkIds);
            }
        }

        return $enriched;
    }
}
