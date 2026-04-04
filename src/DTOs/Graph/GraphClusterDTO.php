<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Graph;

class GraphClusterDTO
{
    public function __construct(
        public readonly int    $id,
        public readonly string $color,
        public readonly int    $nodeCount,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            color: (string) ($data['color'] ?? '#888888'),
            nodeCount: (int) ($data['node_count'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'color' => $this->color,
            'node_count' => $this->nodeCount,
        ];
    }
}
