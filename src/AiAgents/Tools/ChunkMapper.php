<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use Illuminate\Support\Str;
use SimoneBianco\LaravelRagChunks\Models\Chunk;

class ChunkMapper
{
    /**
     * Map a raw chunk array (from toArray()) into the standard agent response format.
     */
    public static function mapItem(array $item): array
    {
        return array_filter([
            'content'    => $item['content'],
            'image_url'  => $item['image_url'] ?? null,
            'relations'  => self::mapRelations($item),
            'prev_chunk' => self::mapNeighbor($item['prev_snippet_id'] ?? null, $item['prev_snippet'] ?? null),
            'next_chunk' => self::mapNeighbor($item['next_snippet_id'] ?? null, $item['next_snippet'] ?? null),
        ]);
    }

    /**
     * Load a Chunk model with all data needed for agent mapping and return the mapped array.
     * Use this when loading a single chunk outside of ChunkService (e.g. navigation tools).
     */
    public static function loadAndMap(Chunk $chunk): array
    {
        $chunk->loadMissing('dedupMedia');
        $chunk->image_url = $chunk->getFirstMedia()?->getUrl();
        $chunk->makeHidden(['embedding', 'questions_embedding', 'tags_embedding', 'dedup_media']);

        return self::mapItem($chunk->toArray());
    }

    /**
     * Map a neighbor chunk (previous or next) into a compact format.
     */
    private static function mapNeighbor(?string $id, ?string $snippet): ?array
    {
        if ($id === null) {
            return null;
        }

        return [
            'id'      => $id,
            'preview' => $snippet !== null ? Str::limit(trim($snippet), 50) : null,
        ];
    }

    /**
     * Extract the last 5 relations (by creation date) from a raw chunk array.
     * Each entry contains the related chunk's ID and the relation name/description.
     * Only relations connected to a Chunk entity are included.
     */
    private static function formatRelationName(array $rel): ?string
    {
        $name        = $rel['name'] ?? null;
        $description = $rel['description'] ?? null;

        if ($name && $description) {
            return "{$name}: {$description}";
        }

        return $name ?? $description ?? null;
    }

    private static function mapRelations(array $item): array
    {
        $isChunkEntity = static fn (string $type): bool => str_ends_with($type, 'Chunk');

        $outgoing = collect($item['outgoing_relations'] ?? [])
            ->filter(fn ($rel) => $isChunkEntity($rel['to_entity_type'] ?? ''))
            ->map(fn ($rel) => [
                'chunk_id'   => $rel['to_entity']['id'] ?? null,
                'name'       => self::formatRelationName($rel),
                'created_at' => $rel['created_at'] ?? null,
            ]);

        $incoming = collect($item['incoming_relations'] ?? [])
            ->filter(fn ($rel) => $isChunkEntity($rel['from_entity_type'] ?? ''))
            ->map(fn ($rel) => [
                'chunk_id'   => $rel['from_entity']['id'] ?? null,
                'name'       => self::formatRelationName($rel),
                'created_at' => $rel['created_at'] ?? null,
            ]);

        return $outgoing->merge($incoming)
            ->filter(fn ($r) => $r['chunk_id'] !== null)
            ->sortByDesc('created_at')
            ->take(5)
            ->map(fn ($r) => ['chunk_id' => $r['chunk_id'], 'name' => $r['name']])
            ->values()
            ->toArray();
    }
}
