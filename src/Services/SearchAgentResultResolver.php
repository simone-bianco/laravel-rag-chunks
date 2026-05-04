<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use SimoneBianco\LaravelRagChunks\AiAgents\Concerns\NormalizesChunkIds;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\ChunkMapper;
use SimoneBianco\LaravelRagChunks\Enums\RelationType;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;
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

            $orderedChunks = $this->expandChunksForExplicitSessionQuery(
                $orderedChunks,
                $entry['query'],
                $scopeProjectId,
            );

            // Collect the query's own chunk IDs for memory deduplication
            $queryChunkIdSet = array_fill_keys(
                $orderedChunks->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all(),
                true,
            );

            $queryMemoryResults = [];

            foreach ($entry['history_ids'] as $historyId) {
                $memoryResult = $this->resolveMemoryResult(
                    $historyId,
                    $historyById,
                    $historyChunkIdsById,
                    $chunksById,
                    $queryChunkIdSet,
                );

                if ($memoryResult === null) {
                    continue;
                }

                if (! $this->isMemoryResultRelevantToQuery($entry['query'], $memoryResult)) {
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
    /**
     * Extract chunk IDs from a results array, supporting both new format
     * (documents.{alias}.chunk_ids) and legacy (chunk_ids[] / relevant_chunks keys).
     *
     * @param array<string, mixed> $results
     * @return array<int, string>
     */
    private function extractChunkIdsFromResults(array $results): array
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

            return $this->normalizeChunkIds($ids);
        }

        // Legacy format: chunk_ids[] or relevant_chunks keys
        return $this->normalizeChunkIds(
            $results['chunk_ids']
                ?? array_keys(is_array($results['relevant_chunks'] ?? null) ? $results['relevant_chunks'] : []),
        );
    }

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
            $historyChunkIdsById[$historyId] = $this->extractChunkIdsFromResults($rawResults);
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
            $historyChunkIdsById[$historyId] = $this->extractChunkIdsFromResults($historyResult);
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
     * @param Collection<int, Chunk> $orderedChunks
     * @return Collection<int, Chunk>
     */
    private function expandChunksForExplicitSessionQuery(Collection $orderedChunks, string $query, ?string $scopeProjectId): Collection
    {
        $sessionNumber = $this->extractSessionNumber($query);
        if ($sessionNumber === null || $orderedChunks->isEmpty()) {
            return $orderedChunks;
        }

        $matchedDocumentIds = $orderedChunks
            ->pluck('document_id')
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();

        if ($matchedDocumentIds === []) {
            return $orderedChunks;
        }

        $candidateDocuments = Document::query()
            ->whereIn('id', $matchedDocumentIds)
            ->get(['id', 'alias', 'name']);

        $sessionDocuments = $candidateDocuments
            ->filter(fn (Document $document): bool => $this->documentMatchesSessionNumber($document, $sessionNumber))
            ->values();

        if ($sessionDocuments->isEmpty()) {
            return $orderedChunks;
        }

        $expandedChunks = Chunk::query()
            ->whereIn('document_id', $sessionDocuments->pluck('id')->all())
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
            ->orderBy('document_id')
            ->orderBy('order')
            ->get()
            ->keyBy('id');

        if ($expandedChunks->isEmpty()) {
            return $orderedChunks;
        }

        $existingById = $orderedChunks
            ->keyBy(static fn (Chunk $chunk): string => (string) $chunk->id);

        foreach ($expandedChunks as $chunkId => $chunk) {
            if (! $existingById->has((string) $chunkId)) {
                $existingById->put((string) $chunkId, $chunk);
            }
        }

        return $existingById->values();
    }

    private function documentMatchesSessionNumber(Document $document, int $sessionNumber): bool
    {
        $alias = mb_strtolower(trim((string) ($document->alias ?? '')));
        $name = mb_strtolower(trim((string) ($document->name ?? '')));

        if ($alias === '' && $name === '') {
            return false;
        }

        $patterns = [
            'sessione-' . $sessionNumber,
            'sessione ' . $sessionNumber,
            'session-' . $sessionNumber,
            'session ' . $sessionNumber,
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($alias, $pattern) || str_contains($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isMemoryResultRelevantToQuery(string $query, array $memoryResult): bool
    {
        $queryTokens = $this->tokenizeForHistoryMatch($query);
        if ($queryTokens === []) {
            return true;
        }

        $minMatchRatio = $this->minMatchRatioForTokens($queryTokens);

        $memoryQuery = is_string($memoryResult['query'] ?? null)
            ? (string) $memoryResult['query']
            : '';
        $memoryNotes = is_string($memoryResult['notes'] ?? null)
            ? (string) $memoryResult['notes']
            : '';
        $memorySummary = is_string($memoryResult['summary'] ?? null)
            ? (string) $memoryResult['summary']
            : '';

        $memoryText = trim($memoryQuery . ' ' . $memoryNotes . ' ' . $memorySummary);
        $memoryTokens = $this->tokenizeForHistoryMatch($memoryText);

        if ($memoryTokens === []) {
            Log::channel('search')->debug('[Resolver] Memory rejected: no tokens', [
                'memory_id' => $memoryResult['id'] ?? '?',
                'query_preview' => mb_substr($query, 0, 120),
            ]);

            return false;
        }

        $overlap = array_values(array_intersect($queryTokens, $memoryTokens));
        $matchRatio = count($overlap) / count($queryTokens);

        if ($matchRatio < $minMatchRatio) {
            Log::channel('search')->debug('[Resolver] Memory rejected: token mismatch', [
                'memory_id' => $memoryResult['id'] ?? '?',
                'query_preview' => mb_substr($query, 0, 120),
                'match_ratio' => round($matchRatio, 2),
                'min_ratio' => round($minMatchRatio, 2),
                'query_tokens' => $queryTokens,
                'memory_tokens' => $memoryTokens,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function tokenizeForHistoryMatch(string $text): array
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return [];
        }

        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized);
        if (! is_string($normalized) || trim($normalized) === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', $normalized) ?: [];

        return array_values(array_unique(array_filter($parts, static fn (string $token): bool => $token !== '')));
    }

    /**
     * @param array<int, string> $queryTokens
     */
    private function minMatchRatioForTokens(array $queryTokens): float
    {
        return count($queryTokens) >= 4 ? 0.6 : 1.0;
    }

    private function extractSessionNumber(string $text): ?int
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/\bsession(?:e)?\s*[-:]?\s*(\d+)\b/u', $normalized, $matches) !== 1) {
            return null;
        }

        $value = (int) ($matches[1] ?? 0);

        return $value > 0 ? $value : null;
    }

    /**
     * @param array<string, array<string, mixed>> $historyById
     * @param array<string, array<int, string>> $historyChunkIdsById
     * @param array<string, true> $queryChunkIdSet Chunk IDs already included in the query result (for dedup)
     */
    private function resolveMemoryResult(
        string $historyId,
        array $historyById,
        array $historyChunkIdsById,
        Collection $chunksById,
        array $queryChunkIdSet = [],
    ): ?array {
        $historyRow = $historyById[$historyId] ?? null;
        if (! is_array($historyRow)) {
            return null;
        }

        $allHistoryChunkIds = $historyChunkIdsById[$historyId] ?? [];

        // Filter out chunk IDs already present in the query result
        $filteredChunkIds = $queryChunkIdSet === []
            ? $allHistoryChunkIds
            : array_values(array_filter(
                $allHistoryChunkIds,
                static fn (string $chunkId): bool => ! isset($queryChunkIdSet[$chunkId]),
            ));

        $resolvedChunks = collect($filteredChunkIds)
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

        // Only include full chunk data when there is no summary AND
        // there are chunks that aren't already in the query result.
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

        // Validate UUID format to prevent SQLSTATE[22P02] on PostgreSQL.
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        return array_values(array_unique(array_filter($ids, static fn (?string $id): bool =>
            $id !== null && $id !== '' && preg_match($pattern, $id) === 1,
        )));
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
