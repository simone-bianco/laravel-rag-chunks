<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Support\Collection;
use SimoneBianco\LaravelRagChunks\AiAgents\Concerns\NormalizesChunkIds;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\ChunkMapper;
use SimoneBianco\LaravelRagChunks\Enums\RelationType;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\SearchResult;

class SearchAgentResultResolver
{
    use NormalizesChunkIds;

    public function resolve(array $results, ?string $scopeProjectId): array
    {
        if ($results === []) {
            return ['results' => [], 'memory_results' => []];
        }

        $prepared = [];
        $allManualChunkIds = [];
        $allHistoryIds = [];

        foreach ($results as $index => $searchResult) {
            if (! is_array($searchResult)) {
                continue;
            }

            $query = is_string($searchResult['query'] ?? null)
                ? trim((string) $searchResult['query'])
                : '';

            $notes = is_string($searchResult['notes'] ?? null)
                ? trim((string) $searchResult['notes'])
                : null;

            $manualChunkIds = $this->normalizeChunkIds($searchResult['relevant_chunks'] ?? []);
            $historyIds = $this->normalizeHistoryIds(
                $searchResult['history_ids']
                    ?? $searchResult['history_result_ids']
                    ?? [],
            );

            $prepared[$index] = [
                'query' => $query,
                'notes' => $notes,
                'manual_chunk_ids' => $manualChunkIds,
                'history_ids' => $historyIds,
                'relevant_images' => is_array($searchResult['relevant_images'] ?? null)
                    ? $searchResult['relevant_images']
                    : [],
            ];

            foreach ($manualChunkIds as $chunkId) {
                $allManualChunkIds[] = $chunkId;
            }

            foreach ($historyIds as $historyId) {
                $allHistoryIds[] = $historyId;
            }
        }

        if ($prepared === []) {
            return ['results' => [], 'memory_results' => []];
        }

        $uniqueHistoryIds = array_values(array_unique($allHistoryIds));

        $historyRows = $uniqueHistoryIds === []
            ? []
            : SearchResultService::make()->getSearchResults($uniqueHistoryIds, $scopeProjectId);

        $historyById = [];
        foreach ($historyRows as $historyRow) {
            if (! is_array($historyRow)) {
                continue;
            }

            $id = isset($historyRow['id']) && is_string($historyRow['id'])
                ? trim($historyRow['id'])
                : '';

            if ($id !== '') {
                $historyById[$id] = $historyRow;
            }
        }

        $historyChunkIdsById = $this->resolveHistoryChunkIds($uniqueHistoryIds, $historyById, $scopeProjectId);
        $chunksById = $this->loadChunksById(array_merge($allManualChunkIds, ...array_values($historyChunkIdsById)), $scopeProjectId);

        $resolvedResults = [];
        $memoryResults = [];
        $seenMemoryIds = [];

        foreach ($prepared as $entry) {
            $orderedChunks = collect($entry['manual_chunk_ids'])
                ->map(static fn (string $id) => $chunksById->get($id))
                ->filter()
                ->values();

            $queryMemoryResults = [];

            foreach ($entry['history_ids'] as $historyId) {
                $memoryResult = $this->resolveMemoryResult(
                    $historyId,
                    $historyById,
                    $historyChunkIdsById,
                    $chunksById,
                );

                if ($memoryResult === null) {
                    continue;
                }

                $queryMemoryResults[] = $memoryResult;

                if (! isset($seenMemoryIds[$historyId])) {
                    $seenMemoryIds[$historyId] = true;
                    $memoryResults[] = $memoryResult;
                }
            }

            $resolvedResults[] = [
                'query' => $entry['query'],
                'notes' => $entry['notes'],
                'chunk_ids' => $orderedChunks->pluck('id')->values()->toArray(),
                'relevant_chunks' => $orderedChunks->mapWithKeys(fn (Chunk $chunk) => [
                    $chunk->id => ChunkMapper::loadAndMap($chunk),
                ])->toArray(),
                'relevant_images' => $this->normalizeRelevantImages($entry['relevant_images']),
                'memory_results' => $queryMemoryResults,
            ];
        }

        return [
            'results' => $resolvedResults,
            'memory_results' => $memoryResults,
        ];
    }

    /**
     * @param array<int, string> $historyIds
     * @param array<string, array<string, mixed>> $historyById
     * @return array<string, array<int, string>>
     */
    private function resolveHistoryChunkIds(array $historyIds, array $historyById, ?string $scopeProjectId): array
    {
        if ($historyIds === []) {
            return [];
        }

        $rawRows = SearchResult::query()
            ->whereIn('id', $historyIds)
            ->when(is_string($scopeProjectId) && $scopeProjectId !== '', static fn ($query) => $query->where('project_id', $scopeProjectId))
            ->get(['id', 'results']);

        $historyChunkIdsById = [];

        foreach ($rawRows as $rawRow) {
            $historyId = (string) $rawRow->id;
            $rawResults = is_array($rawRow->results) ? $rawRow->results : [];

            $historyChunkIdsById[$historyId] = $this->normalizeChunkIds(
                $rawResults['chunk_ids']
                    ?? array_keys(is_array($rawResults['relevant_chunks'] ?? null) ? $rawResults['relevant_chunks'] : []),
            );
        }

        foreach ($historyIds as $historyId) {
            if (isset($historyChunkIdsById[$historyId])) {
                continue;
            }

            $historyRow = $historyById[$historyId] ?? null;
            if (! is_array($historyRow)) {
                continue;
            }

            $historyResult = is_array($historyRow['results'] ?? null)
                ? $historyRow['results']
                : [];

            $historyChunkIdsById[$historyId] = $this->normalizeChunkIds(
                $historyResult['chunk_ids']
                    ?? array_keys(is_array($historyResult['relevant_chunks'] ?? null) ? $historyResult['relevant_chunks'] : []),
            );
        }

        return $historyChunkIdsById;
    }

    /**
     * @param array<int, string> $chunkIds
     * @return Collection<string, Chunk>
     */
    private function loadChunksById(array $chunkIds, ?string $scopeProjectId): Collection
    {
        $uniqueChunkIds = array_values(array_unique(array_filter($chunkIds, static fn (mixed $chunkId): bool => is_string($chunkId) && $chunkId !== '')));

        if ($uniqueChunkIds === []) {
            return collect();
        }

        return Chunk::query()
            ->whereIn('id', $uniqueChunkIds)
            ->when(is_string($scopeProjectId) && $scopeProjectId !== '', function ($query) use ($scopeProjectId): void {
                $query->whereHas('document', fn ($documentQuery) => $documentQuery->where('project_id', $scopeProjectId));
            })
            ->with([
                'dedupMedia',
                'outgoingRelations.to_entity',
                'incomingRelations' => function ($query) {
                    $query->where('type', RelationType::BIDIRECTIONAL->value)->with('from_entity');
                },
            ])
            ->withNeighborSnippets()
            ->get()
            ->keyBy('id');
    }

    /**
     * @param array<string, array<string, mixed>> $historyById
     * @param array<string, array<int, string>> $historyChunkIdsById
     */
    private function resolveMemoryResult(
        string $historyId,
        array $historyById,
        array $historyChunkIdsById,
        Collection $chunksById,
    ): ?array {
        $historyRow = $historyById[$historyId] ?? null;
        if (! is_array($historyRow)) {
            return null;
        }

        $resolvedChunks = collect($historyChunkIdsById[$historyId] ?? [])
            ->map(static fn (string $chunkId) => $chunksById->get($chunkId))
            ->filter()
            ->values();

        $summary = is_string($historyRow['summary'] ?? null)
            ? trim((string) $historyRow['summary'])
            : '';

        $hasSummary = $summary !== '';
        $chunksCount = $resolvedChunks->count();

        $memoryResult = [
            'id' => $historyId,
            'query' => is_string($historyRow['query'] ?? null)
                ? trim((string) $historyRow['query'])
                : '',
            'notes' => is_string($historyRow['notes'] ?? null)
                ? trim((string) $historyRow['notes'])
                : null,
            'chunks_count' => $chunksCount,
        ];

        if ($hasSummary) {
            $memoryResult['summary'] = $summary;
        }

        // Only include full chunk data when there is no summary.
        // Summarized memories carry enough context in the summary field
        // and returning all chunks would bloat the payload unnecessarily.
        if (! $hasSummary && $chunksCount > 0) {
            $memoryResult['chunks'] = $resolvedChunks->map(fn (Chunk $chunk): array => [
                'id' => (string) $chunk->id,
                ...ChunkMapper::loadAndMap($chunk),
            ])->values()->toArray();
        }

        return $memoryResult;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeHistoryIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = array_map(
            static fn (mixed $id): ?string => is_string($id) ? trim($id) : null,
            $value,
        );

        return array_values(array_unique(array_filter($ids, static fn (?string $id): bool => $id !== null && $id !== '')));
    }

    /**
     * @param array<int, mixed> $images
     * @return array<int, array{url: string, content: string}>
     */
    private function normalizeRelevantImages(array $images): array
    {
        $normalized = [];

        foreach ($images as $image) {
            if (! is_array($image)) {
                continue;
            }

            $url = isset($image['url']) && is_string($image['url']) ? trim($image['url']) : '';
            if ($url === '') {
                continue;
            }

            $content = isset($image['content']) && is_string($image['content'])
                ? trim($image['content'])
                : '';

            $normalized[] = [
                'url' => $url,
                'content' => $content,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $left
     * @param array<int, mixed> $right
     * @return array<int, array{url: string, content: string}>
     */
    private function mergeRelevantImages(array $left, array $right): array
    {
        $merged = [];
        $seen = [];

        foreach ([$this->normalizeRelevantImages($left), $this->normalizeRelevantImages($right)] as $imageList) {
            foreach ($imageList as $image) {
                $url = $image['url'];
                if (isset($seen[$url])) {
                    continue;
                }

                $seen[$url] = true;
                $merged[] = $image;
            }
        }

        return $merged;
    }
}
