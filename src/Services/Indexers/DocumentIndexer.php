<?php

namespace SimoneBianco\LaravelRagChunks\Services\Indexers;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SimoneBianco\LaravelRagChunks\AiAgents\IndexerAgent;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;

class DocumentIndexer
{
    public function indexDocument(Document $document, ?array $chunkIds = null, array $initialIndex = []): array
    {
        $currentIndex = $initialIndex;

        $query = $document->chunks();
        if (! empty($chunkIds)) {
            $query->whereIn('id', $chunkIds);
        }

        $query->chunkById(20, function (Collection $chunks) use (&$currentIndex, $document) {
            $mappedChunks = $chunks->map(function (Chunk $chunk) {
                return [
                    'uuid' => $chunk->id,
                    'content' => $chunk->content
                ];
            });

            $cleanIndex = array_keys($currentIndex);

            $results = new IndexerAgent(Str::random())
                ->withChunks($mappedChunks->toArray())
                ->withDocument($document)
                ->withIndex($cleanIndex)
                ->respond();

            foreach ($results['chunks_with_chapter'] ?? [] as $chunkRef => $content) {
                $chunkUuid = Str::after($chunkRef, 'chunk_');
                $title = $content['chapter_title'];

                $alias = preg_replace('/[^A-Za-z0-9\-]/', '', Str::kebab(strtolower($title)));

                $currentIndex[$alias] ??= [];
                $currentIndex[$alias][] = $chunkUuid;
            }
        });

        return $currentIndex;
    }
}
