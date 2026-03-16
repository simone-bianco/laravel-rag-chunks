<?php

namespace SimoneBianco\LaravelRagChunks\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use SimoneBianco\LaravelRagChunks\Models\Document;

class DocumentMetadataGeneratedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly Document $document,
        public readonly string $processId,
        public readonly array $metadata,
        public readonly bool $success = true,
        public readonly ?string $error = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('document.' . (string) $this->document->getKey())];
    }

    public function broadcastAs(): string
    {
        return 'document.metadata.generated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $metadata = $this->normalizeMetadata($this->metadata);

        return [
            'document_id' => (string) $this->document->getKey(),
            'process_id' => $this->processId,
            'metadata' => $metadata,
            'success' => $this->success,
            'error' => $this->error,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    protected function normalizeMetadata(array $metadata): array
    {
        $classicTagsByType = $this->buildClassicTagsByType();

        $name = array_key_exists('name', $metadata)
            ? trim((string) ($metadata['name'] ?? ''))
            : trim((string) ($this->document->name ?? ''));

        $description = array_key_exists('description', $metadata)
            ? trim((string) ($metadata['description'] ?? ''))
            : trim((string) ($this->document->description ?? ''));

        return [
            'name' => $name,
            'description' => $description,
            'classic_tags' => is_array($metadata['classic_tags'] ?? null)
                ? $metadata['classic_tags']
                : $classicTagsByType,
            'classic_tags_by_type' => is_array($metadata['classic_tags_by_type'] ?? null)
                ? $metadata['classic_tags_by_type']
                : $classicTagsByType,
            'semantic_tags' => is_array($metadata['semantic_tags'] ?? null)
                ? array_values($metadata['semantic_tags'])
                : array_values((array) ($this->document->semantic_tags ?? [])),
            'questions' => is_array($metadata['questions'] ?? null)
                ? array_values($metadata['questions'])
                : array_values((array) ($this->document->questions ?? [])),
        ];
    }

    /**
     * @return array<int|string, array<int, mixed>>
     */
    protected function buildClassicTagsByType(): array
    {
        return $this->document
            ->tags()
            ->get(['id', 'tag_type_id'])
            ->groupBy('tag_type_id')
            ->map(fn ($tags) => $tags->pluck('id')->values()->all())
            ->toArray();
    }
}
