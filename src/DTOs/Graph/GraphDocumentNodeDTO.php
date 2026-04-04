<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Graph;

class GraphDocumentNodeDTO
{
    /**
     * @param string[] $chunkIds
     */
    public function __construct(
        public readonly string $id,
        public readonly string $alias,
        public readonly string $name,
        public readonly float  $centroidX,
        public readonly float  $centroidY,
        public readonly array  $chunkIds,
        public readonly int    $clusterId,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            alias: (string) ($data['alias'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            centroidX: (float) ($data['centroid_x'] ?? 0.0),
            centroidY: (float) ($data['centroid_y'] ?? 0.0),
            chunkIds: array_map('strval', $data['chunk_ids'] ?? []),
            clusterId: (int) ($data['cluster_id'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'alias' => $this->alias,
            'name' => $this->name,
            'centroid_x' => $this->centroidX,
            'centroid_y' => $this->centroidY,
            'chunk_ids' => $this->chunkIds,
            'cluster_id' => $this->clusterId,
        ];
    }
}
