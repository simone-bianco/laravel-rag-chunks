<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SimoneBianco\LaravelRagChunks\DTOs\ChunkFilterDataDTO;
use SimoneBianco\LaravelRagChunks\DTOs\ChunkSearchDataDTO;
use SimoneBianco\LaravelRagChunks\Enums\RelationType;
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
            ->with([
                'document',
                'dedupMedia',
                'outgoingRelations.to_entity',
                'incomingRelations' => function ($q) {
                    $q->where('type', RelationType::BIDIRECTIONAL->value)->with('from_entity');
                },
            ])
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

    public function filter(ChunkFilterDataDTO $filterData): LengthAwarePaginator
    {
        $contentVector   = null;
        $tagsVector      = null;
        $questionsVector = null;

        if (! empty($filterData->semanticText)) {
            $contentVector = Embedding::embed($filterData->semanticText);
        }
        if (! empty($filterData->semanticTags)) {
            $tagsVector = Embedding::embed($filterData->semanticTags);
        }
        if (! empty($filterData->semanticQuestions)) {
            $questionsVector = Embedding::embed($filterData->semanticQuestions);
        }

        $hasSemanticSearch = $contentVector !== null || $tagsVector !== null || $questionsVector !== null;

        $query = Chunk::query()
            ->select('*')
            ->with([
                'dedupMedia',
                'outgoingRelations.to_entity',
                'incomingRelations' => function ($q) {
                    $q->where('type', RelationType::BIDIRECTIONAL->value)->with('from_entity');
                },
            ])
            ->whereDocumentId($filterData->documentId)
            ->whereKeywordSearch($filterData->text, $filterData->caseSensitive)
            ->whereContentLength($filterData->charMin, $filterData->charMax)
            ->whereDirty($filterData->isDirty)
            ->whereHasEmbedding($filterData->hasEmbedding)
            ->whereChunkTags($filterData->chunkTagGroups);

        if ($hasSemanticSearch) {
            $query->withHybridRanking(
                contentVector: $contentVector,
                questionsVector: $questionsVector,
                tagsVector: $tagsVector,
                weightContent: null,
                weightQuestions: null,
                weightTags: null,
            );
        } else {
            $query->orderBy('order');
        }

        $paginator = $query->paginate(
                $filterData->perPage,
                ['*'],
                'page',
                $filterData->page,
            );

        $this->transformForFrontend($paginator->getCollection());

        return $paginator;
    }

    /**
     * Transform a collection of chunks for frontend consumption:
     * adds image_url, hides embeddings, serializes relations.
     */
    public function transformForFrontend(Collection $chunks): Collection
    {
        return $chunks->transform(function (Chunk $chunk) {
            $chunk->image_url = $chunk->getFirstMedia()?->getUrl();
            unset($chunk->embedding, $chunk->tags_embedding, $chunk->questions_embedding);

            $relations = [];

            foreach ($chunk->outgoingRelations ?? [] as $relation) {
                $entity = $relation->to_entity;
                if ($entity) {
                    $relations[] = [
                        'id'          => $relation->id,
                        'type'        => $relation->type->value,
                        'name'        => $relation->name,
                        'description' => $relation->description,
                        'direction'   => 'outgoing',
                        'entity'      => $this->serializeRelationEntity($entity),
                    ];
                }
            }

            foreach ($chunk->incomingRelations ?? [] as $relation) {
                $entity = $relation->from_entity;
                if ($entity) {
                    $relations[] = [
                        'id'          => $relation->id,
                        'type'        => $relation->type->value,
                        'name'        => $relation->name,
                        'description' => $relation->description,
                        'direction'   => 'incoming',
                        'entity'      => $this->serializeRelationEntity($entity),
                    ];
                }
            }

            $chunk->relations = $relations;

            return $chunk;
        });
    }

    private function serializeRelationEntity(Model $entity): array
    {
        $type = strtolower(class_basename($entity));

        $label = match ($type) {
            'chunk'    => 'Chunk #' . $entity->order,
            'document' => $entity->name,
            default    => ucfirst($type) . ' #' . $entity->getKey(),
        };

        return [
            'type'            => $type,
            'id'              => $entity->getKey(),
            'label'           => $label,
            'content_preview' => $type === 'chunk' ? Str::limit($entity->content, 80) : null,
        ];
    }
}
