<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use SimoneBianco\LaravelRagChunks\DTOs\ChunkSearchDataDTO;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Search;

class ChunkService
{
    public function __construct(
        protected ?string $chunkModel = null,
    ) {
        $config = config('rag_chunks', []);
        $this->chunkModel ??= $config['chunk_model'] ?? Chunk::class;
    }

    public function search(ChunkSearchDataDTO $searchData): LengthAwarePaginator
    {
        return $this->chunkModel::select('*')
            ->withNeighborSnippets()
            ->when(!empty($searchData->documentsAliases), function (Builder $query) use ($searchData) {
                $query->whereIn('document_id', $searchData->documentsAliases);
            })->when(!empty($searchData->anyTags), function (Builder $query) use ($searchData) {
                $query->whereHas('document', function (Builder $q) use ($searchData) {
                    $q->withAnyTagsOfAnyType($searchData->anyTags);
                });
            })->when(!empty($searchData->allTags), function (Builder $query) use ($searchData) {
                $query->whereHas('document', function (Builder $q) use ($searchData) {
                    $q->withAllTagsOfAnyType($searchData->allTags);
                });
            })->when(!empty($searchData->allTagsByType), function (Builder $query) use ($searchData) {
                foreach ($searchData->allTagsByType as $type => $tags) {
                    $query->whereHas('document', function (Builder $q) use ($tags, $type) {
                        $q->withAllTags($tags, $type);
                    });
                }
            })->when(!empty($searchData->anyTagsByType), function (Builder $query) use ($searchData) {
                $query->whereHas('document', function (Builder $q) use ($searchData) {
                    foreach ($searchData->anyTagsByType as $type => $tags) {
                        $q->withAnyTags($tags, $type);
                    }
                });
            })->when(!empty($searchData->search), function (Builder $query) use ($searchData) {
                $searchDataEmbedding = Search::embed($searchData->search);
                $vector = '[' . implode(',', $searchDataEmbedding) . ']';

                $query->nearestNeighbors('embedding', $searchDataEmbedding, 'cosine')
                    ->selectRaw('1 - (embedding <=> ?) as content_similarity', [$vector])
                    ->selectRaw('1 - (embedding <=> ?) as similarity', [$vector]);
            })->paginate(
                $searchData->perPage,
                ['*'],
                'page',
                $searchData->page
            );
    }
}
