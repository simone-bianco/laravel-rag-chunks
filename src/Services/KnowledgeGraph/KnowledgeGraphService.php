<?php

namespace SimoneBianco\LaravelRagChunks\Services\KnowledgeGraph;

use SimoneBianco\LaravelRagChunks\DTOs\Graph\GraphClusterDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Graph\GraphDocumentNodeDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Graph\GraphEdgeDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Graph\GraphNodeDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Graph\KnowledgeGraphDTO;

class KnowledgeGraphService
{
    public function __construct(
        private readonly GraphCacheService      $cache,
        private readonly GraphProjectionService $projection,
    ) {}

    /**
     * Return the knowledge graph for a project.
     *
     * Returns cached version when the version fingerprint matches,
     * otherwise recomputes from the Python projection service.
     */
    public function getGraph(string $projectId): KnowledgeGraphDTO
    {
        $cached = $this->cache->get($projectId);

        if ($cached !== null) {
            $currentVersion = $this->cache->computeVersion($projectId);

            if ($cached->version === $currentVersion) {
                return $cached;
            }
        }

        return $this->computeAndCache($projectId);
    }

    /**
     * Force-compute the graph via the Python service, then cache it.
     */
    public function computeAndCache(string $projectId): KnowledgeGraphDTO
    {
        $raw = $this->projection->project($projectId);

        $dto = $this->buildDTO($projectId, $raw);

        $this->cache->put($projectId, $dto);

        return $dto;
    }

    /**
     * Invalidate the cached graph for a project.
     */
    public function invalidate(string $projectId): void
    {
        $this->cache->invalidate($projectId);
    }

    /**
     * Check whether a fresh cached version exists for the project.
     */
    public function hasFreshCache(string $projectId): bool
    {
        $cached = $this->cache->get($projectId);

        if ($cached === null) {
            return false;
        }

        return $cached->version === $this->cache->computeVersion($projectId);
    }

    private function buildDTO(string $projectId, array $raw): KnowledgeGraphDTO
    {
        $chunkNodes = array_map(
            static fn (array $n) => GraphNodeDTO::fromArray($n),
            $raw['nodes'] ?? [],
        );

        $documentNodes = array_map(
            static fn (array $n) => GraphDocumentNodeDTO::fromArray($n),
            $raw['document_nodes'] ?? [],
        );

        $edges = array_map(
            static fn (array $e) => GraphEdgeDTO::fromArray($e),
            $raw['edges'] ?? [],
        );

        $clusters = array_map(
            static fn (array $c) => GraphClusterDTO::fromArray($c),
            $raw['clusters'] ?? [],
        );

        $maxEdges = config('rag_chunks.knowledge_graph.max_graph_edges', 15000);

        if (count($edges) > $maxEdges) {
            $edges = array_slice($edges, 0, $maxEdges);
        }

        return new KnowledgeGraphDTO(
            projectId: $projectId,
            chunkNodes: $chunkNodes,
            documentNodes: $documentNodes,
            edges: $edges,
            clusters: $clusters,
            computedAt: now()->toIso8601String(),
            version: $this->cache->computeVersion($projectId),
        );
    }
}
