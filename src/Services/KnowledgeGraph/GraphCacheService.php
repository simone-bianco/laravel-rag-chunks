<?php

namespace SimoneBianco\LaravelRagChunks\Services\KnowledgeGraph;

use Illuminate\Support\Facades\Cache;
use SimoneBianco\LaravelRagChunks\DTOs\Graph\KnowledgeGraphDTO;
use SimoneBianco\LaravelRagChunks\Models\Chunk;

class GraphCacheService
{
    private const TAG_GLOBAL = 'knowledge_graph';

    public function get(string $projectId): ?KnowledgeGraphDTO
    {
        $data = Cache::tags($this->tags($projectId))->get($this->key($projectId));

        if ($data === null) {
            return null;
        }

        return KnowledgeGraphDTO::fromCacheArray($data);
    }

    public function put(string $projectId, KnowledgeGraphDTO $dto): void
    {
        $ttl = config('rag_chunks.knowledge_graph.cache_ttl', 3600);

        Cache::tags($this->tags($projectId))
            ->put($this->key($projectId), $dto->toArray(), $ttl);
    }

    public function invalidate(string $projectId): void
    {
        Cache::tags(["knowledge_graph:{$projectId}"])->flush();
    }

    /**
     * Compute a lightweight version fingerprint from two cheap aggregate queries.
     *
     * Returns "{max_updated_at}:{count}" so the caller can detect staleness
     * without loading any actual chunk data.
     */
    public function computeVersion(string $projectId): string
    {
        $query = Chunk::query()
            ->whereHas('document', fn ($q) => $q->where('project_id', $projectId))
            ->whereNotNull('embedding');

        $maxUpdatedAt = (clone $query)->max('updated_at') ?? '0';
        $count = (clone $query)->count();

        return "{$maxUpdatedAt}:{$count}";
    }

    public function key(string $projectId): string
    {
        return "knowledge_graph:{$projectId}";
    }

    /**
     * @return string[]
     */
    private function tags(string $projectId): array
    {
        return [self::TAG_GLOBAL, "knowledge_graph:{$projectId}"];
    }
}
