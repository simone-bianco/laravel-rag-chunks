<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools\Memory;

use Illuminate\Support\Facades\Log;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Tool;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\MemoryMergeAgent;
use SimoneBianco\LaravelRagChunks\AiAgents\MemoryNoteReducerAgent;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\ChunkMapper;
use SimoneBianco\LaravelRagChunks\Enums\RelationType;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\SearchResult;
use SimoneBianco\LaravelRagChunks\Services\SearchResultService;

class SaveSearchesResultsTool extends Tool
{
    protected ?string $creatorAgentId = null;

    /**
     * Cosine distance threshold for auto-compaction.
     * If a newly saved result has cosine distance < threshold from
     * an existing result in the same project, the two are merged.
     * Default 0.1 (very similar). Configurable via factory::compaction_threshold.
     */
    protected float $compactionThreshold = 0.1;

    public function __construct(
        protected ?string $scopeProjectId = null,
        ?string $name = 'save_searches_results',
        ?string $description = 'Persist final search outcomes. Call once at the end when search_chunks ran (including no-result outcomes), and skip only for history-only reuse with no search_chunks call.'
    ) {
        parent::__construct($name, $description);
    }

    /**
     * Set the cosine distance threshold for auto-compaction.
     * When a new result is saved, if an existing result in the same project
     * has cosine distance below this threshold, the two are merged instead
     * of creating a duplicate.
     */
    public function compactionThreshold(float $threshold): self
    {
        $this->compactionThreshold = max(0.0, min(1.0, $threshold));

        return $this;
    }

    public function creatorAgentId(?string $agentId): self
    {
        $normalized = is_string($agentId) ? trim($agentId) : '';
        $this->creatorAgentId = $normalized !== '' ? $normalized : null;

        return $this;
    }

    public function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    public function getProperties(): array
    {
        return [
            'searchResults' => [
                'type'        => 'array',
                'description' => 'Final search-result groups for this run. Include curated reusable findings, or a no-result outcome when search_chunks was executed and nothing relevant was found.',
                'items'       => [
                    'type'        => 'object',
                    'description' => 'One reusable search result produced at the end of this search session.',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'The reusable query/description for this result set: one concise sentence that explains what the chunks answer.',
                        ],
                        'notes' => [
                            'type' => 'string',
                            'description' => 'MANDATORY ultra-short note in caveman style (at most 30 words). Only core contents/coverage keywords, no full sentences.',
                        ],
                        'chunk_ids' => [
                            'type' => 'array',
                            'description' => 'Final relevant chunk UUIDs for this query. Use [] only for a final no-result outcome after executing search_chunks.',
                            'items'       => [
                                'type'        => 'string',
                                'description' => 'Chunk UUID.',
                            ],
                        ],
                        'relevant_images' => [
                            'type' => 'array',
                            'description' => 'Relevant images connected to this result set, when any were found.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'url' => ['type' => 'string', 'description' => 'Image URL.'],
                                    'content' => ['type' => 'string', 'description' => 'Brief description of what the image shows.'],
                                ],
                                'required' => ['url', 'content'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['query', 'notes', 'chunk_ids'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    protected array $required = ['searchResults'];

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    protected function handle(array|DataModel $input): mixed
    {
        $data   = is_array($input) ? $input : $input->toArray();
        $schema = is_array($data) ? $data : [];

        $searchResults = $schema['searchResults'] ?? [];
        if (! is_array($searchResults) || empty($searchResults)) {
            $this->logger()->warning('[SaveSearchesResultsTool] Empty save request');

            return ['error' => 'searchResults cannot be empty'];
        }

        $this->logger()->info('[SaveSearchesResultsTool] Save requested', [
            'requested_count' => count($searchResults),
        ]);

        $saved = [];
        $skipped = [];
        $compacted = [];

        foreach (array_values($searchResults) as $index => $item) {
            if (! is_array($item)) {
                $skipped[] = ['index' => $index, 'reason' => 'invalid_item'];
                continue;
            }

            $query = $this->normalizeQuery($item);
            $notes = $this->normalizeNotes($item);
            $chunkIds = $this->normalizeChunkIds($item['chunk_ids'] ?? $item['chunks_uuids'] ?? $item['relevant_chunks'] ?? []);

            $isEmptyOutcome = ($chunkIds === []);
            if ($isEmptyOutcome && ($notes === null || trim($notes) === '')) {
                $notes = 'search done no relevant chunks found';
            }

            $notes = MemoryNoteReducerAgent::reduce($query, $notes);

            if ($query === '') {
                $skipped[] = ['index' => $index, 'reason' => 'empty_query'];
                continue;
            }

            if ($isEmptyOutcome) {
                $payload = [
                    'chunk_ids' => [],
                    'relevant_chunks' => [],
                    'relevant_images' => [],
                ];
            } else {
                $payload = $this->buildResultPayload($chunkIds, $item['relevant_images'] ?? []);
                if (empty($payload['chunk_ids'])) {
                    $skipped[] = ['index' => $index, 'reason' => 'no_project_chunks', 'query' => mb_substr($query, 0, 200)];
                    continue;
                }
            }

            $projectId = $this->resolveProjectId($payload['chunk_ids']);
            if ($projectId === null) {
                $skipped[] = ['index' => $index, 'reason' => 'missing_project_context', 'query' => mb_substr($query, 0, 200)];
                continue;
            }

            if ($this->creatorAgentId === null) {
                $skipped[] = [
                    'index' => $index,
                    'reason' => 'missing_creator_agent_id',
                    'query' => mb_substr($query, 0, 200),
                    'project_id' => $projectId,
                ];
                $this->logger()->warning('[SaveSearchesResultsTool] Skip save: missing creator agent id', [
                    'project_id' => $projectId,
                    'query_preview' => mb_substr($query, 0, 200),
                ]);
                continue;
            }

            $row = SearchResultService::make()->save($query, $payload, $this->creatorAgentId, $projectId, $notes);

            $this->logger()->debug('[SaveSearchesResultsTool] Saved row, checking compaction', [
                'save_id' => (string) $row->id,
                'project_id' => $projectId,
                'has_embedding' => is_array($row->embedding) && $row->embedding !== [],
                'threshold' => $this->compactionThreshold,
            ]);

            // Auto-compact: find similar existing results and merge if within threshold.
            // Wrapped in try/catch — compaction is a best-effort optimization.
            // If the pgvector query or merge fails, the save still succeeds.
            if ($isEmptyOutcome) {
                $compactionResult = null;
            } else {
                try {
                    $compactionResult = $this->compactIfSimilar($row, $projectId);
                } catch (\Throwable $e) {
                    $this->logger()->warning('[SaveSearchesResultsTool] Auto-compaction failed, saving normally', [
                        'error' => $e->getMessage(),
                        'error_class' => get_class($e),
                        'save_id' => (string) $row->id,
                        'project_id' => $projectId,
                        'threshold' => $this->compactionThreshold,
                        'trace' => $e->getTraceAsString(),
                    ]);
                    $compactionResult = null;
                }
            }

            if ($compactionResult !== null) {
                // Result was merged into an existing one; the new record was deleted
                $compacted[] = $compactionResult;
                $saved[] = [
                    'id' => $compactionResult['merged_into_id'],
                    'query' => $compactionResult['merged_query'],
                    'chunks_count' => $compactionResult['merged_chunks_count'],
                    'project_id' => $projectId,
                    'notes' => $compactionResult['merged_notes'],
                    'compacted' => true,
                    'merged_from_id' => (string) $row->id,
                ];
            } else {
                $saved[] = [
                    'id' => (string) $row->id,
                    'query' => $query,
                    'chunks_count' => count($payload['chunk_ids']),
                    'project_id' => $projectId,
                    'notes' => $notes,
                ];
            }
        }

        $this->logger()->info('[SaveSearchesResultsTool] Save completed', [
            'requested_count' => count($searchResults),
            'saved_count' => count($saved),
            'skipped_count' => count($skipped),
            'compacted_count' => count($compacted),
            'saved_ids' => array_column($saved, 'id'),
            'saved_project_ids' => array_values(array_unique(array_filter(array_column($saved, 'project_id')))),
            'saved_with_notes_count' => count(array_filter(array_column($saved, 'notes'), static fn (mixed $n): bool => is_string($n) && $n !== '')),
            'skipped' => $skipped,
        ]);

        return [
            'status' => count($saved) > 0 ? 'saved' : 'nothing_saved',
            'saved_count' => count($saved),
            'skipped_count' => count($skipped),
            'saved_ids' => array_column($saved, 'id'),
            'compacted_count' => count($compacted),
        ];
    }

    /**
     * Find similar existing results in the same project and merge if within threshold.
     *
     * Uses pgvector cosine distance (<=>) for efficient nearest-neighbor lookup,
     * then MemoryMergeAgent for AI-powered query/notes merge.
     *
     * @param SearchResult $newResult The newly saved result
     * @param string $projectId The project ID to search within
     * @return array|null Compaction info if merged, null if no similar result found
     */
    private function compactIfSimilar(SearchResult $newResult, string $projectId): ?array
    {
        if ($this->compactionThreshold <= 0.0) {
            return null;
        }

        $embedding = is_array($newResult->embedding) ? $newResult->embedding : null;
        if ($embedding === null || $embedding === []) {
            return null;
        }

        /** @var SearchResult|null $similar */
        $similar = SearchResult::query()
            ->where('project_id', $projectId)
            ->where('id', '!=', $newResult->id)
            ->whereNotNull('embedding')
            ->nearestNeighbors('embedding', $embedding)
            ->first();

        if ($similar === null) {
            return null;
        }

        $similarEmbedding = is_array($similar->embedding) ? $similar->embedding : [];
        $distance = $this->cosineDistance($embedding, $similarEmbedding);

        if ($distance === null || $distance >= $this->compactionThreshold) {
            $this->logger()->debug('[SaveSearchesResultsTool] Similar candidate found but distance above threshold', [
                'project_id' => $projectId,
                'new_id' => (string) $newResult->id,
                'candidate_id' => (string) $similar->id,
                'distance' => $distance,
                'threshold' => $this->compactionThreshold,
            ]);

            return null;
        }

        // Capture pre-merge state for logging
        $preMerge = [
            'existing' => [
                'id' => (string) $similar->id,
                'query' => $similar->query,
                'notes' => is_string($similar->notes) ? $similar->notes : null,
                'hits' => (int) $similar->hits,
                'chunk_ids_count' => is_array($similar->results['chunk_ids'] ?? null) ? count($similar->results['chunk_ids']) : 0,
            ],
            'new' => [
                'id' => (string) $newResult->id,
                'query' => $newResult->query,
                'notes' => is_string($newResult->notes) ? $newResult->notes : null,
                'hits' => (int) $newResult->hits,
                'chunk_ids_count' => is_array($newResult->results['chunk_ids'] ?? null) ? count($newResult->results['chunk_ids']) : 0,
            ],
        ];

        // Merge query/notes using MemoryMergeAgent (AI-powered with deterministic fallback)
        $merged = MemoryMergeAgent::merge([
            ['query' => $similar->query, 'notes' => is_string($similar->notes) ? $similar->notes : null],
            ['query' => $newResult->query, 'notes' => is_string($newResult->notes) ? $newResult->notes : null],
        ]);

        $similar->query = $merged['query'] ?? $similar->query;
        $similar->notes = MemoryNoteReducerAgent::reduce(
            $similar->query,
            $merged['notes'] ?? $similar->notes,
        );

        // Sum hits
        $similar->hits = ((int) $similar->hits) + ((int) $newResult->hits);

        // Merge results (chunk_ids union + relevant_chunks union)
        $similar->results = $this->mergeResultsData($similar, $newResult);

        // Average embeddings
        $mergedEmbedding = $this->averageEmbeddings(
            is_array($similar->embedding) ? $similar->embedding : [],
            is_array($newResult->embedding) ? $newResult->embedding : [],
        );
        if ($mergedEmbedding !== []) {
            $similar->embedding = $mergedEmbedding;
        }

        $similar->save();

        // Delete the new record — it was merged into the existing one
        $newResult->delete();

        // Detailed input → output log for debugging
        $this->logger()->info('[SaveSearchesResultsTool] Auto-compacted similar result', [
            'project_id' => $projectId,
            'distance' => $distance,
            'threshold' => $this->compactionThreshold,
            'input' => $preMerge,
            'output' => [
                'id' => (string) $similar->id,
                'query' => $similar->query,
                'notes' => is_string($similar->notes) ? $similar->notes : null,
                'hits' => (int) $similar->hits,
                'chunk_ids_count' => is_array($similar->results['chunk_ids'] ?? null) ? count($similar->results['chunk_ids']) : 0,
            ],
            'deleted_id' => (string) $newResult->id,
        ]);

        return [
            'merged_into_id' => (string) $similar->id,
            'merged_query' => $similar->query,
            'merged_notes' => $similar->notes,
            'merged_chunks_count' => is_array($similar->results['chunk_ids'] ?? null) ? count($similar->results['chunk_ids']) : 0,
        ];
    }

    /**
     * Merge results data from two SearchResults (union of chunk_ids and relevant_chunks).
     *
     * @param SearchResult $existing The existing result (base)
     * @param SearchResult $new The new result being merged in
     * @return array<string, mixed>
     */
    private function mergeResultsData(SearchResult $existing, SearchResult $new): array
    {
        $base = is_array($existing->results) ? $existing->results : [];

        $existingChunkIds = is_array($base['chunk_ids'] ?? null) ? $base['chunk_ids'] : [];
        $newChunkIds = is_array($new->results['chunk_ids'] ?? null) ? $new->results['chunk_ids'] : [];

        $chunkIds = array_values(array_unique(array_merge(
            $existingChunkIds,
            $newChunkIds,
        )));

        $existingRelevantChunks = is_array($base['relevant_chunks'] ?? null) ? $base['relevant_chunks'] : [];
        $newRelevantChunks = is_array($new->results['relevant_chunks'] ?? null) ? $new->results['relevant_chunks'] : [];

        foreach ($newRelevantChunks as $chunkId => $chunkPayload) {
            if (is_string($chunkId) && $chunkId !== '' && is_array($chunkPayload) && ! isset($existingRelevantChunks[$chunkId])) {
                $existingRelevantChunks[$chunkId] = $chunkPayload;
            }
        }

        $base['chunk_ids'] = $chunkIds;

        if ($existingRelevantChunks !== []) {
            $base['relevant_chunks'] = $existingRelevantChunks;
        }

        return $base;
    }

    /**
     * @param array<int, float> $left
     * @param array<int, float> $right
     */
    private function cosineDistance(array $left, array $right): ?float
    {
        $left = array_values(array_map(static fn (mixed $value): float => (float) $value, $left));
        $right = array_values(array_map(static fn (mixed $value): float => (float) $value, $right));

        $dimension = count($left);
        if ($dimension === 0 || $dimension !== count($right)) {
            return null;
        }

        $dot = 0.0;
        $leftMagnitude = 0.0;
        $rightMagnitude = 0.0;

        for ($index = 0; $index < $dimension; $index++) {
            $dot += $left[$index] * $right[$index];
            $leftMagnitude += $left[$index] ** 2;
            $rightMagnitude += $right[$index] ** 2;
        }

        if ($leftMagnitude <= 0.0 || $rightMagnitude <= 0.0) {
            return null;
        }

        $similarity = $dot / (sqrt($leftMagnitude) * sqrt($rightMagnitude));
        $clampedSimilarity = max(-1.0, min(1.0, $similarity));

        return max(0.0, min(1.0, 1.0 - $clampedSimilarity));
    }

    /**
     * Average two embedding vectors.
     *
     * @param array<int, float> $a
     * @param array<int, float> $b
     * @return array<int, float>
     */
    private function averageEmbeddings(array $a, array $b): array
    {
        $a = array_values(array_map(static fn (mixed $v): float => (float) $v, $a));
        $b = array_values(array_map(static fn (mixed $v): float => (float) $v, $b));

        $dimension = count($a);
        if ($dimension === 0 || $dimension !== count($b)) {
            return $a !== [] ? $a : ($b !== [] ? $b : []);
        }

        $result = [];
        for ($i = 0; $i < $dimension; $i++) {
            $result[] = ($a[$i] + $b[$i]) / 2.0;
        }

        return $result;
    }

    private function normalizeQuery(array $item): string
    {
        $query = $item['query'] ?? $item['description'] ?? '';

        return is_string($query) ? trim($query) : '';
    }

    private function normalizeChunkIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $ids[] = trim($item);
                continue;
            }

            if (is_string($key)) {
                $ids[] = trim($key);
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
    }

    private function normalizeNotes(array $item): ?string
    {
        $notes = $item['notes'] ?? null;
        if (! is_string($notes)) {
            return null;
        }

        $trimmed = trim($notes);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function buildResultPayload(array $chunkIds, mixed $relevantImages): array
    {
        $chunks = Chunk::query()
            ->whereIn('id', $chunkIds)
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

        $orderedChunks = collect($chunkIds)
            ->map(static fn (string $id) => $chunks->get($id))
            ->filter()
            ->values();

        return [
            'chunk_ids' => $orderedChunks->pluck('id')->values()->toArray(),
            'relevant_chunks' => $orderedChunks->mapWithKeys(static fn (Chunk $chunk): array => [
                (string) $chunk->id => ChunkMapper::loadAndMap($chunk),
            ])->toArray(),
            'relevant_images' => $this->normalizeRelevantImages($relevantImages),
        ];
    }

    private function normalizeRelevantImages(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (mixed $image): ?array {
            if (! is_array($image)) {
                return null;
            }

            $url = $image['url'] ?? null;
            if (! is_string($url) || trim($url) === '') {
                return null;
            }

            $content = $image['content'] ?? '';

            return [
                'url' => trim($url),
                'content' => is_string($content) ? trim($content) : '',
            ];
        }, $value)));
    }

    /**
     * @param array<int, string> $chunkIds
     */
    private function resolveProjectId(array $chunkIds): ?string
    {
        if (is_string($this->scopeProjectId) && $this->scopeProjectId !== '') {
            return $this->scopeProjectId;
        }

        if ($chunkIds === []) {
            return null;
        }

        $projectIds = Chunk::query()
            ->whereIn('id', $chunkIds)
            ->with('document:id,project_id')
            ->get()
            ->pluck('document.project_id')
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();

        if ($projectIds->isEmpty()) {
            return null;
        }

        if ($projectIds->count() > 1) {
            $this->logger()->warning('[SaveSearchesResultsTool] Multiple project IDs detected while saving; using first', [
                'project_ids' => $projectIds->all(),
            ]);
        }

        return (string) $projectIds->first();
    }
}
