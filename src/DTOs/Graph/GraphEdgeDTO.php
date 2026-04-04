<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Graph;

class GraphEdgeDTO
{
    public function __construct(
        public readonly string $sourceId,
        public readonly string $targetId,
        public readonly float  $similarity,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            sourceId: (string) ($data['source_id'] ?? ''),
            targetId: (string) ($data['target_id'] ?? ''),
            similarity: (float) ($data['similarity'] ?? 0.0),
        );
    }

    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'target_id' => $this->targetId,
            'similarity' => $this->similarity,
        ];
    }
}
