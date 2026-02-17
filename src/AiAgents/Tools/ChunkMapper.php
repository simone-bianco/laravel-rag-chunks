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
            'document_id'   => $item['document_id'],
            'previous_chunk' => array_filter(['content' => $item['prev_snippet'] ?? null]),
            'content'       => $item['content'],
            'next_chunk'    => array_filter(['content' => $item['next_snippet'] ?? null]),
            'semantic_tags' => $item['semantic_tags'] ?? $item['tags'] ?? [],
            'questions'     => $item['questions'] ?? [],
            'image_url'     => $item['image_url'] ?? null,
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
}
