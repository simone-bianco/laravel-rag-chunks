<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Graph;

class KnowledgeGraphDTO
{
    /**
     * @param GraphNodeDTO[]         $chunkNodes
     * @param GraphDocumentNodeDTO[] $documentNodes
     * @param GraphEdgeDTO[]         $edges
     * @param GraphClusterDTO[]      $clusters
     */
    public function __construct(
        public readonly string $projectId,
        public readonly array  $chunkNodes,
        public readonly array  $documentNodes,
        public readonly array  $edges,
        public readonly array  $clusters,
        public readonly string $computedAt,
        public readonly string $version,
    ) {}

    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'chunk_nodes' => array_map(
                static fn (GraphNodeDTO $node) => $node->toArray(),
                $this->chunkNodes,
            ),
            'document_nodes' => array_map(
                static fn (GraphDocumentNodeDTO $node) => $node->toArray(),
                $this->documentNodes,
            ),
            'edges' => array_map(
                static fn (GraphEdgeDTO $edge) => $edge->toArray(),
                $this->edges,
            ),
            'clusters' => array_map(
                static fn (GraphClusterDTO $cluster) => $cluster->toArray(),
                $this->clusters,
            ),
            'computed_at' => $this->computedAt,
            'version' => $this->version,
        ];
    }

    /**
     * Reconstruct from cached toArray() output.
     * Used in GraphCacheService to deserialize from cache.
     */
    public static function fromCacheArray(array $data): self
    {
        $chunkNodes = array_map(
            static fn (array $n) => GraphNodeDTO::fromArray($n),
            $data['chunk_nodes'] ?? [],
        );

        $documentNodes = array_map(
            static fn (array $n) => GraphDocumentNodeDTO::fromArray($n),
            $data['document_nodes'] ?? [],
        );

        $edges = array_map(
            static fn (array $e) => GraphEdgeDTO::fromArray($e),
            $data['edges'] ?? [],
        );

        $clusters = array_map(
            static fn (array $c) => GraphClusterDTO::fromArray($c),
            $data['clusters'] ?? [],
        );

        return new self(
            projectId: (string) ($data['project_id'] ?? ''),
            chunkNodes: $chunkNodes,
            documentNodes: $documentNodes,
            edges: $edges,
            clusters: $clusters,
            computedAt: (string) ($data['computed_at'] ?? ''),
            version: (string) ($data['version'] ?? ''),
        );
    }
}
