<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Graph;

class GraphNodeDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $documentId,
        public readonly string $documentAlias,
        public readonly float  $x,
        public readonly float  $y,
        public readonly int    $size,
        public readonly int    $clusterId,
        public readonly string $contentSnippet,
        public readonly string $chapter,
        public readonly int    $order,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            documentId: (string) ($data['document_id'] ?? ''),
            documentAlias: (string) ($data['document_alias'] ?? ''),
            x: (float) ($data['x'] ?? 0.0),
            y: (float) ($data['y'] ?? 0.0),
            size: (int) ($data['size'] ?? 1),
            clusterId: (int) ($data['cluster_id'] ?? 0),
            contentSnippet: (string) ($data['content_snippet'] ?? ''),
            chapter: (string) ($data['chapter'] ?? ''),
            order: (int) ($data['order'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->documentId,
            'document_alias' => $this->documentAlias,
            'x' => $this->x,
            'y' => $this->y,
            'size' => $this->size,
            'cluster_id' => $this->clusterId,
            'content_snippet' => $this->contentSnippet,
            'chapter' => $this->chapter,
            'order' => $this->order,
        ];
    }
}
