<?php

namespace SimoneBianco\LaravelRagChunks\Services\Indexers;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SimoneBianco\LaravelRagChunks\AiAgents\IndexerAgent;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;

class DocumentIndexer
{
    public function indexDocument(Document $document): array
    {
        $currentIndex = [];
        $document->chunks()->chunkById(10, function (Collection $chunks) use (&$currentIndex, $document) {
            $mappedChunks = $chunks->map(function (Chunk $chunk) {
                return [
                    'uuid' => $chunk->id,
                    'content' => $chunk->content
                ];
            });

            $cleanIndex = array_combine(
                Arr::pluck($currentIndex, 'alias'),
                array_fill(0, count($currentIndex), [])
            );

            $results = new IndexerAgent(Str::random())
                ->withChunks($mappedChunks->toArray())
                ->withDocument($document)
                ->withIndex($cleanIndex)
                ->respond();

            $chunksByChapter = Arr::mapWithKeys($results['chunks_by_chapter'] ?? [], function ($item) {
                $alias = preg_replace('/[^A-Za-z0-9\-]/', '', Str::kebab(strtolower($item['chapter_title'])));
                return [$alias => [
                    'title' => $item['chapter_title'],
                    'alias' => $alias,
                    'chunks_ids' => $item['chunks_uuids']
                ]];
            });

            foreach ($chunksByChapter as $chapterAlias => $item) {
                $currentIndex[$chapterAlias] = [...$currentIndex[$chapterAlias] ?? [], ...$item['chunks_ids']];
            }
        });

        return $currentIndex;
    }
}
