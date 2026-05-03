<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use SimoneBianco\LaravelRagChunks\AiAgents\SearchResultOptimizationAgent;
use SimoneBianco\LaravelRagChunks\AiAgents\SearchResultSummaryAgent;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\ChunkMapper;
use SimoneBianco\LaravelRagChunks\Enums\RelationType;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Embedding;
use SimoneBianco\LaravelRagChunks\Models\SearchResult;

class SearchResultMemoryOptimizationService
{
    /** @return array<string, mixed> */
    public function optimizeIfNeeded(
        SearchResult $memory,
        int $chunkThreshold,
        bool $forceSplit = false,
        bool $summarizeWhenUnderThreshold = false,
        bool $forceDecision = false,
        array $allowedOperations = ['compact', 'split'],
    ): array {
        $chunkThreshold = max(1, $chunkThreshold);
        $allowedOperations = $this->normalizeAllowedOperations($allowedOperations);

        if ($allowedOperations === []) {
            return ['action' => 'keep', 'reason' => 'automatic_operations_disabled'];
        }

        $memory->refresh();

        $chunkIds = $this->chunkIds(is_array($memory->results) ? $memory->results : []);
        if ($chunkIds === []) {
            return ['action' => 'keep', 'reason' => 'no_chunks'];
        }

        $chunkCount = count($chunkIds);
        if ($chunkCount <= $chunkThreshold && ! $forceDecision) {
            if ($summarizeWhenUnderThreshold || $this->summary($memory) !== null) {
                if (! in_array('compact', $allowedOperations, true)) {
                    return ['action' => 'keep', 'reason' => 'compact_disabled'];
                }

                return $this->compact($memory, 'under_threshold_summary_refresh');
            }

            return ['action' => 'keep', 'reason' => 'under_threshold', 'chunks_count' => $chunkCount];
        }

        $chunks = $this->orderedProjectChunks($memory);
        if ($chunks->isEmpty()) {
            return ['action' => 'keep', 'reason' => 'no_project_chunks'];
        }

        $decision = SearchResultOptimizationAgent::optimize(
            $this->query($memory),
            $this->notes($memory),
            $this->summary($memory),
            $this->buildChunkCatalog($chunks),
            $forceSplit && in_array('split', $allowedOperations, true),
        );

        if ($forceSplit || $decision['action'] === 'split') {
            if (! in_array('split', $allowedOperations, true)) {
                if (in_array('compact', $allowedOperations, true)) {
                    return $this->compact($memory, 'split_disabled_compact_fallback');
                }

                return ['action' => 'keep', 'reason' => 'split_disabled', 'chunks_count' => $chunkCount];
            }

            return $this->split($memory, $chunks, $decision['groups']);
        }

        if ($decision['action'] === 'compact') {
            if (! in_array('compact', $allowedOperations, true)) {
                return ['action' => 'keep', 'reason' => 'compact_disabled', 'chunks_count' => $chunkCount];
            }

            $summary = $decision['summary'] ?? null;
            if (! is_string($summary) || trim($summary) === '') {
                return $this->compact($memory, 'agent_compact_fallback');
            }

            $memory->forceFill(['summary' => trim($summary)])->save();

            return ['action' => 'compact', 'memory_id' => (string) $memory->id, 'chunks_count' => $chunkCount];
        }

        if ($forceDecision) {
            if (! in_array('compact', $allowedOperations, true)) {
                return ['action' => 'keep', 'reason' => 'compact_disabled_forced_decision', 'chunks_count' => $chunkCount];
            }

            return $this->compact($memory, 'forced_decision_compact_fallback');
        }

        return ['action' => 'keep', 'reason' => 'agent_keep', 'chunks_count' => $chunkCount];
    }

    /** @param array<int, string> $operations @return array<int, string> */
    private function normalizeAllowedOperations(array $operations): array
    {
        $allowed = ['compact' => true, 'split' => true];

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $operation): ?string => is_string($operation) ? trim($operation) : null, $operations),
            static fn (?string $operation): bool => is_string($operation) && isset($allowed[$operation]),
        )));
    }

    /** @return array<string, mixed> */
    private function compact(SearchResult $memory, string $reason): array
    {
        $input = $this->buildFullChunkInput($memory);
        if ($input === '') {
            return ['action' => 'keep', 'reason' => 'no_compactable_text'];
        }

        $summarySeed = $this->summary($memory);
        $summaryInput = $summarySeed !== null
            ? "Existing summary:\n{$summarySeed}\n\nNew/backing chunks:\n{$input}"
            : $input;

        $summary = SearchResultSummaryAgent::summarize($this->query($memory), $this->notes($memory), $summaryInput);
        if (trim($summary) === '') {
            return ['action' => 'keep', 'reason' => 'empty_summary'];
        }

        $memory->forceFill(['summary' => trim($summary)])->save();

        return ['action' => 'compact', 'reason' => $reason, 'memory_id' => (string) $memory->id];
    }

    /** @param EloquentCollection<int, Chunk> $chunks @param array<int, array{query: string, notes: ?string, chunk_ids: array<int, string>}> $groups @return array<string, mixed> */
    private function split(SearchResult $memory, EloquentCollection $chunks, array $groups): array
    {
        if ($groups === []) {
            $groups = $this->fallbackSplitGroups($memory, $chunks);
        }

        $allowed = array_fill_keys($chunks->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all(), true);
        $created = [];

        foreach ($groups as $group) {
            $chunkIds = array_values(array_filter(
                $group['chunk_ids'],
                static fn (string $chunkId): bool => isset($allowed[$chunkId]),
            ));

            if ($chunkIds === []) {
                continue;
            }

            $created[] = SearchResult::create([
                'ai_agent_id' => $this->agentId($memory),
                'project_id' => $this->projectId($memory),
                'query' => $group['query'],
                'notes' => $group['notes'],
                'summary' => null,
                'results' => $this->payloadForChunkIds($chunkIds),
                'embedding' => Embedding::embed($group['query']),
                'hits' => (int) $memory->hits,
            ]);
        }

        if (count($created) < 2) {
            return ['action' => 'keep', 'reason' => 'split_created_less_than_two'];
        }

        $deletedId = (string) $memory->id;
        $memory->delete();

        Log::channel('search')->info('[SearchResultMemoryOptimizationService] Memory split automatically', [
            'deleted_id' => $deletedId,
            'created_ids' => array_map(static fn (SearchResult $row): string => (string) $row->id, $created),
        ]);

        return [
            'action' => 'split',
            'deleted_id' => $deletedId,
            'created_count' => count($created),
            'created_ids' => array_map(static fn (SearchResult $row): string => (string) $row->id, $created),
        ];
    }

    private function buildFullChunkInput(SearchResult $memory): string
    {
        $chunks = $this->orderedProjectChunks($memory);

        return $chunks
            ->groupBy(static fn (Chunk $chunk): string => (string) ($chunk->document?->alias ?? 'unknown'))
            ->map(fn (Collection $documentChunks): string => $this->formatDocumentChunks($documentChunks))
            ->implode("\n\n---\n\n");
    }

    /** @param EloquentCollection<int, Chunk> $chunks */
    private function buildChunkCatalog(EloquentCollection $chunks): string
    {
        return $chunks
            ->map(static function (Chunk $chunk): string {
                $document = $chunk->document;
                $content = trim(preg_replace('/\s+/u', ' ', (string) $chunk->content) ?? '');
                $preview = mb_substr($content, 0, 900);

                return implode("\n", array_filter([
                    'id: ' . (string) $chunk->id,
                    'document: ' . (string) ($document?->name ?? $document?->alias ?? 'unknown'),
                    is_string($chunk->chapter) && trim($chunk->chapter) !== '' ? 'chapter: ' . trim($chunk->chapter) : null,
                    'content: ' . $preview,
                ]));
            })
            ->implode("\n\n");
    }

    /** @param Collection<int, Chunk> $chunks */
    private function formatDocumentChunks(Collection $chunks): string
    {
        /** @var Chunk $first */
        $first = $chunks->first();
        $document = $first->document;
        $description = is_string($document?->description) && trim($document->description) !== ''
            ? "\nDescription: " . trim($document->description)
            : '';

        $body = $chunks
            ->map(static fn (Chunk $chunk): string => trim(implode("\n", array_filter([
                is_string($chunk->prev_snippet) ? trim($chunk->prev_snippet) : null,
                trim((string) $chunk->content),
                is_string($chunk->next_snippet) ? trim($chunk->next_snippet) : null,
            ], static fn (?string $part): bool => is_string($part) && $part !== ''))))
            ->implode("\n\n");

        return trim("Document: {$document?->name} ({$document?->alias}){$description}\n\n{$body}");
    }

    /** @return EloquentCollection<int, Chunk> */
    private function orderedProjectChunks(SearchResult $memory): EloquentCollection
    {
        $chunkIds = $this->chunkIds(is_array($memory->results) ? $memory->results : []);
        $projectId = $this->projectId($memory);
        if ($chunkIds === [] || $projectId === null) {
            return new EloquentCollection();
        }

        $chunks = Chunk::query()
            ->whereIn('id', $chunkIds)
            ->whereHas('document', static fn ($query) => $query->where('project_id', $projectId))
            ->with('document:id,alias,name,description,project_id')
            ->withNeighborSnippets()
            ->get()
            ->keyBy('id');

        return new EloquentCollection(collect($chunkIds)
            ->map(static fn (string $id): ?Chunk => $chunks->get($id))
            ->filter()
            ->values()
            ->all());
    }

    /** @param EloquentCollection<int, Chunk> $chunks @return array<int, array{query: string, notes: ?string, chunk_ids: array<int, string>}> */
    private function fallbackSplitGroups(SearchResult $memory, EloquentCollection $chunks): array
    {
        $documentGroups = $chunks
            ->groupBy(static fn (Chunk $chunk): string => (string) ($chunk->document?->alias ?? 'unknown'))
            ->map(function (EloquentCollection $documentChunks, string $documentAlias) use ($memory): array {
                $first = $documentChunks->first();
                $documentName = $first instanceof Chunk ? (string) ($first->document?->name ?? $documentAlias) : $documentAlias;

                return [
                    'query' => trim($this->query($memory) . ' — ' . $documentName),
                    'notes' => $this->notes($memory),
                    'chunk_ids' => $documentChunks->pluck('id')->map(static fn (mixed $id): string => (string) $id)->values()->all(),
                ];
            })
            ->values()
            ->all();

        if (count($documentGroups) >= 2) {
            return $documentGroups;
        }

        $chunkIds = $chunks->pluck('id')->map(static fn (mixed $id): string => (string) $id)->values()->all();
        $size = max(1, (int) ceil(count($chunkIds) / 2));

        return array_values(array_map(
            fn (array $ids, int $index): array => [
                'query' => trim($this->query($memory) . ' — part ' . ($index + 1)),
                'notes' => $this->notes($memory),
                'chunk_ids' => $ids,
            ],
            array_chunk($chunkIds, $size),
            array_keys(array_chunk($chunkIds, $size)),
        ));
    }

    /** @param array<int, string> $chunkIds @return array<string, mixed> */
    private function payloadForChunkIds(array $chunkIds): array
    {
        $chunks = Chunk::query()
            ->whereIn('id', $chunkIds)
            ->with([
                'dedupMedia',
                'outgoingRelations.to_entity',
                'incomingRelations' => static function ($query): void {
                    $query->where('type', RelationType::BIDIRECTIONAL->value)->with('from_entity');
                },
            ])
            ->withNeighborSnippets()
            ->get()
            ->keyBy('id');

        $orderedChunks = collect($chunkIds)
            ->map(static fn (string $id): ?Chunk => $chunks->get($id))
            ->filter()
            ->values();

        return [
            'chunk_ids' => $orderedChunks->pluck('id')->map(static fn (mixed $id): string => (string) $id)->values()->all(),
            'relevant_chunks' => $orderedChunks->mapWithKeys(static fn (Chunk $chunk): array => [
                (string) $chunk->id => ChunkMapper::loadAndMap($chunk),
            ])->toArray(),
            'relevant_images' => [],
        ];
    }

    /** @param array<string, mixed> $results @return array<int, string> */
    private function chunkIds(array $results): array
    {
        $ids = is_array($results['chunk_ids'] ?? null)
            ? $results['chunk_ids']
            : array_keys(is_array($results['relevant_chunks'] ?? null) ? $results['relevant_chunks'] : []);

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): ?string => is_string($id) ? trim($id) : null, $ids),
            static fn (?string $id): bool => $id !== null && $id !== '',
        )));
    }

    private function query(SearchResult $memory): string
    {
        return trim((string) $memory->query);
    }

    private function notes(SearchResult $memory): ?string
    {
        return is_string($memory->notes) && trim($memory->notes) !== '' ? trim($memory->notes) : null;
    }

    private function summary(SearchResult $memory): ?string
    {
        return is_string($memory->summary) && trim($memory->summary) !== '' ? trim($memory->summary) : null;
    }

    private function agentId(SearchResult $memory): ?string
    {
        return is_string($memory->ai_agent_id) && trim($memory->ai_agent_id) !== '' ? trim($memory->ai_agent_id) : null;
    }

    private function projectId(SearchResult $memory): ?string
    {
        return is_string($memory->project_id) && trim($memory->project_id) !== '' ? trim($memory->project_id) : null;
    }
}
