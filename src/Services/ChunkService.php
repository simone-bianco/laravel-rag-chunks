<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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
            ->with('tags')
            ->when(!empty($searchData->anyTags), function (Builder $query) use ($searchData) {
                $query->withAnyTagsOfAnyType($searchData->anyTags);
            })->when(!empty($searchData->allTags), function (Builder $query) use ($searchData) {
                $query->withAllTagsOfAnyType($searchData->allTags);
            })->when(!empty($searchData->allTagsByType), function (Builder $query) use ($searchData) {
                foreach ($searchData->allTagsByType as $type => $tags) {
                    $query->withAllTags($tags, $type);
                }
            })->when(!empty($searchData->anyTagsByType), function (Builder $query) use ($searchData) {
                foreach ($searchData->anyTagsByType as $type => $tags) {
                    $query->withAnyTags($tags, $type);
                }
            })->when(!empty($searchData->search), function (Builder $query) use ($searchData) {
                $searchDataEmbedding = Search::embed($searchData->search);
                $vector = '[' . implode(',', $searchDataEmbedding) . ']';

                $query->nearestNeighbors('description_embedding', $searchDataEmbedding, 'cosine')
                    ->selectRaw('1 - (description_embedding <=> ?) as description_similarity', [$vector])
                    ->selectRaw('1 - (description_embedding <=> ?) as similarity', [$vector]);
            })->paginate(
                $searchData->perPage,
                ['*'],
                'page',
                $searchData->page
            );
    }
}
