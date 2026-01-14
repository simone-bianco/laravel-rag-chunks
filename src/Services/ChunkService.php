<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use SimoneBianco\LaravelRagChunks\DTOs\ChunkSearchDataDTO;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Embedding;

class ChunkService
{
    public function __construct(
        protected ?string $chunkModel = null,
    ) {
        $config = config('rag_chunks', []);
        $this->chunkModel ??= Arr::get($config, 'model.chunk', Chunk::class);
    }

    public function search(ChunkSearchDataDTO $searchData): LengthAwarePaginator
    {
        $contentVector = null;
        $tagsVector = null;

        if (!empty($searchData->search)) {
            $contentVector = Embedding::embed($searchData->search);
        }

        if (!empty($searchData->semanticTagsSearch)) {
            if ($searchData->semanticTagsSearch === $searchData->search && $contentVector !== null) {
                $tagsVector = $contentVector;
            } else {
                $tagsVector = Embedding::embed($searchData->semanticTagsSearch);
            }
        }

        return $this->chunkModel::query()
            ->select('*')
            ->with('document')
            ->withNeighborSnippets()
            ->whereBasicFilters($searchData->chunksIds, $searchData->textSearch)
            ->whereKeywords($searchData->keywords)
            ->whereAliases($searchData->documentsAliases, $searchData->projectsAliases)
            ->whereTagFilters($searchData->tagFilters)
            ->withHybridRanking(
                contentVector: $contentVector,
                tagsVector: $tagsVector,
                weightContent: $searchData->weightContent,
                weightTags: $searchData->weightSemanticTags
            )
            ->paginate(
                $searchData->perPage,
                ['*'],
                'page',
                $searchData->page
            );
    }
}
