<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use SimoneBianco\LaravelRagChunks\DTOs\ChunkSearchDataDTO;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Embedding;

class ChunkService
{
    public function search(ChunkSearchDataDTO $searchData): LengthAwarePaginator
    {
        $contentVector = null;
        $questionsVector = null;
        $tagsVector = null;

        // Embed textSearch for content similarity
        if (! empty($searchData->textSearch)) {
            $contentVector = Embedding::embed($searchData->textSearch);
        }

        // Embed questionsSearch for questions similarity
        if (! empty($searchData->questionsSearch)) {
            // Reuse contentVector if same text
            if ($searchData->questionsSearch === $searchData->textSearch && $contentVector !== null) {
                $questionsVector = $contentVector;
            } else {
                $questionsVector = Embedding::embed($searchData->questionsSearch);
            }
        }

        // Embed semanticTagsSearch for tags similarity
        if (! empty($searchData->semanticTagsSearch)) {
            // Reuse vectors if same text
            if ($searchData->semanticTagsSearch === $searchData->textSearch && $contentVector !== null) {
                $tagsVector = $contentVector;
            } elseif ($searchData->semanticTagsSearch === $searchData->questionsSearch && $questionsVector !== null) {
                $tagsVector = $questionsVector;
            } else {
                $tagsVector = Embedding::embed($searchData->semanticTagsSearch);
            }
        }

        $paginator = Chunk::query()
            ->select('*')
            ->with(['document', 'dedupMedia'])
            ->whereHas('document', function (Builder $query) {
                $query->where('enabled', true);
            })
            ->withNeighborSnippets()
            ->whereBasicFilters($searchData->chunksIds, $searchData->textSearch, $searchData->keywordsSearch)
            ->whereAliases($searchData->documentsAliases, $searchData->projectsAliases)
            ->whereTagFilters($searchData->tagFilters)
            ->withHybridRanking(
                contentVector: $contentVector,
                questionsVector: $questionsVector,
                tagsVector: $tagsVector,
                weightContent: $searchData->weightContent,
                weightQuestions: $searchData->weightQuestions,
                weightTags: $searchData->weightSemanticTags
            )
            ->paginate(
                $searchData->perPage,
                ['*'],
                'page',
                $searchData->page
            );

        $paginator->through(function (Chunk $chunk) use ($searchData) {
            $chunk->image_url = $chunk->getFirstMedia()?->getUrl();
            $chunk->makeHidden(['dedup_media']);

            if (! $searchData->includeEmbeddings) {
                $chunk->makeHidden(['embedding', 'questions_embedding', 'tags_embedding']);
            }

            return $chunk;
        });

        return $paginator;
    }
}
