<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use SimoneBianco\LaravelRagChunks\DTOs\ChunkSearchDataDTO;
use SimoneBianco\LaravelRagChunks\Enums\TagFilterMode;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Embedding;

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
            ->with('document')
            ->withNeighborSnippets()
            ->when(!empty($searchData->documentsAliases), function (Builder $query) use ($searchData) {
                $query->whereHas('document', function (Builder $q) use ($searchData) {
                    $q->whereIn('alias', $searchData->documentsAliases);
                });
            })
            ->when(!empty($searchData->projectsAliases), function (Builder $query) use ($searchData) {
                $query->whereHas('document.project', function (Builder $q) use ($searchData) {
                    $q->whereIn('alias', $searchData->projectsAliases);
                });
            })
            ->when(!empty($searchData->chunksIds), function (Builder $query) use ($searchData) {
                $query->whereIn('id', $searchData->chunksIds);
            })
            ->when($searchData->tagFilters->isNotEmpty(), function (Builder $query) use ($searchData) {
                $query->whereHas('document', function (Builder $docQuery) use ($searchData) {
                    foreach ($searchData->tagFilters as $filter) {
                        $tags = [$filter->tag];
                        $type = $filter->type;

                        if ($filter->rule_filter === TagFilterMode::ALL) {
                            $docQuery->withAllTags($tags, $type);
                        } else {
                            $docQuery->withAnyTags($tags, $type);
                        }
                    }
                });
            })->when(!empty($searchData->search), function (Builder $query) use ($searchData) {
                $searchDataEmbedding = Embedding::embed($searchData->search);
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
