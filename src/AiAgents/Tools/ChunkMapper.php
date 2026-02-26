<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use SimoneBianco\LaravelRagChunks\Models\Chunk;

class ChunkMapper
{
    /**
     * Map a raw chunk array (from toArray()) into the standard agent response format.
     */
    public static function mapItem(array $item): array
    {
        return array_filter([
            'document_id'    => $item['document_id'],
            'previous_chunk' => array_filter(['content' => $item['prev_snippet'] ?? null]),
            'content'        => $item['content'],
            'next_chunk'     => array_filter(['content' => $item['next_snippet'] ?? null]),
            'semantic_tags'  => $item['semantic_tags'] ?? $item['tags'] ?? [],
            'questions'      => $item['questions'] ?? [],
            'image_url'      => $item['image_url'] ?? null,
            'relations'      => self::mapRelations($item),
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
