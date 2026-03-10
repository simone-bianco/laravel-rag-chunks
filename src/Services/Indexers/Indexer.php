<?php

namespace SimoneBianco\LaravelRagChunks\Services\PostProcessors;

use Illuminate\Support\Collection;
use SimoneBianco\LaravelRagChunks\Models\Document;

class Indexer
{
    public function indexDocument(Document $document): array
    {
        $currentIndex = [];
        $chunks = $document->chunks()->chunkById(10, function (Collection $chunks) use (&$currentIndex, $document) {

        });
    }
}
