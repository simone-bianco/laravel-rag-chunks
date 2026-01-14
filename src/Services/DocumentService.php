<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SimoneBianco\LaravelRagChunks\Drivers\Embedding\Contracts\EmbeddingDriverInterface;
use SimoneBianco\LaravelRagChunks\DTOs\DocumentDTO;
use SimoneBianco\LaravelRagChunks\DTOs\DocumentSearchDataDTO;
use SimoneBianco\LaravelRagChunks\Enums\TagFilterMode;
use SimoneBianco\LaravelRagChunks\Factories\EmbeddingFactory;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Embedding;
use SimoneBianco\LaravelRagChunks\Facades\HashService;

class DocumentService
{
    public function __construct(
        protected ?string $chunkModel = null,
        protected ?string $documentModel = null,
        protected ?EmbeddingDriverInterface $embeddingDriver = null,
    ) {
        $config = config('rag_chunks', []);
        $this->chunkModel ??= $config['chunk_model'] ?? Chunk::class;
        $this->documentModel ??= $config['document_model'] ?? Document::class;
        $this->embeddingDriver ??= EmbeddingFactory::make();
    }

    public function getOrCreateDocument(DocumentDTO $dto): Document
    {
        /** @var Document $document */
        $document = $this->documentModel::query()
            ->firstOrCreate([
                'alias' => $dto->alias,
            ], [
                'project_id' => $dto->project_id,
                'hash' => $dto->hash ?? HashService::hash($dto->text),
                'name' => $dto->name,
                'name_embedding' => $dto->name ? Embedding::embed($dto->name) : null,
                'description' => $dto->description,
                'description_embedding' => $dto->description ? Embedding::embed($dto->description) : null,
                'metadata' => $dto->metadata,
            ]);

        $document->project_id = $dto->project_id;
        $document->name = $dto->name ?? $document->name;
        $document->description = $dto->description ?? $document->description;
        if ($document->isDirty('name') || ($document->name && $document->name_embedding === null)) {
            $document->name_embedding = $document->name ? $this->embeddingDriver->embed($document->name) : null;
        }
        if ($document->isDirty('description') || ($document->description && $document->description_embedding === null)) {
            $document->description_embedding = $document->description ? $this->embeddingDriver->embed($document->description) : null;
        }
        $document->metadata = $dto->metadata;

        if ($document->isDirty()) {
            $document->save();
        }

        $document->tags()->detach();

        foreach ($dto->tags as $type => $tags) {
            $document->attachTags($tags, $type);
        }

        return $document;
    }

    public function regenerateChunks(DocumentDTO $documentData, array $rawChunks): Document
    {
        $rawChunksData = array_map(function ($rawChunk) {
            return [
                'content' => $rawChunk,
                'hash' => HashService::hash($rawChunk),
            ];
        }, $rawChunks);

        /** @var Collection<string, Chunk> $existingChunks */
        $existingChunks = $this->chunkModel::query()
            ->select(['hash', 'content', 'embedding'])
            ->distinct()
            ->whereIn('hash', Arr::pluck($rawChunksData, 'hash'))
            ->get()
            ->keyBy('hash');

        $createdChunks = collect();
        foreach ($rawChunksData as $index => $data) {
            $hash = $data['hash'];

            if ($existingChunks->has($hash)) {
                $existingChunk = $existingChunks->get($hash);
                $chunk = (new $this->chunkModel)->fill([
                    'content' => $existingChunk->content,
                    'hash' => $existingChunk->hash,
                    'embedding' => $existingChunk->embedding,
                ]);
            } else {
                $embedding = $this->embeddingDriver->embed($data['content']);

                $chunk = (new $this->chunkModel)->fill([
                    'content' => $data['content'],
                    'hash' => $hash,
                    'embedding' => $embedding,
                ]);
            }

            $chunk->page = $index + 1;
            $createdChunks->push($chunk);
        }

        return $this->documentModel::query()
            ->getConnection()
            ->transaction(function () use ($documentData, $createdChunks) {
                $document = $this->getOrCreateDocument($documentData);
                $document->chunks()->delete();
                $document->chunks()->saveMany($createdChunks->all());

                $document->chunks->each(function ($chunk) use ($documentData) {
                    foreach ($documentData->tags as $type => $tags) {
                        $chunk->attachTags($tags, $type);
                    }
                });

                return $document;
            });
    }

    public function search(DocumentSearchDataDTO $searchData)
    {
        $query = $this->documentModel::select('*')
            ->with(['tags', 'project'])
            ->when(! empty($searchData->documentsAliases), function (Builder $query) use ($searchData) {
                $query->whereIn('alias', $searchData->documentsAliases);
            })
            ->when(! empty($searchData->projectsAliases), function (Builder $query) use ($searchData) {
                $query->whereHas('project', function ($q) use ($searchData) {
                    $q->whereIn('alias', $searchData->projectsAliases);
                });
            })
            ->when(! empty($searchData->documentNameSearch), function (Builder $query) use ($searchData) {
                $nameEmbedding = Embedding::embed($searchData->documentNameSearch);
                $vector = '['.implode(',', $nameEmbedding).']';

                $query->nearestNeighbors('name_embedding', $nameEmbedding, 'cosine')
                    ->selectRaw('1 - (name_embedding <=> ?) as name_similarity', [$vector])
                    ->selectRaw('1 - (name_embedding <=> ?) as similarity', [$vector]);
            })
            ->when($searchData->tagFilters->isNotEmpty(), function (Builder $query) use ($searchData) {
                foreach ($searchData->tagFilters as $filter) {
                    $tags = [$filter->tag];
                    $type = $filter->type;

                    if ($filter->rule_filter === TagFilterMode::ALL) {
                         $query->withAllTags($tags, $type);
                    } else {
                         $query->withAnyTags($tags, $type);
                    }
                }
            })
            ->when(! empty($searchData->documentDescriptionSearch), function (Builder $query) use ($searchData) {
                $descriptionEmbedding = Embedding::embed($searchData->documentDescriptionSearch);
                $vector = '['.implode(',', $descriptionEmbedding).']';

                $query->nearestNeighbors('description_embedding', $descriptionEmbedding, 'cosine')
                    ->selectRaw('1 - (description_embedding <=> ?) as description_similarity', [$vector])
                    ->selectRaw('1 - (description_embedding <=> ?) as similarity', [$vector]);
            });

        return $query->paginate(
                $searchData->perPage,
                ['*'],
                'page',
                $searchData->page
            );
    }

    public function findExistingDocument(string $projectId, ?string $hash, ?string $alias): ?Document
    {
        return $this->documentModel::where('project_id', $projectId)
            ->where(function ($q) use ($hash, $alias) {
                $q->where('hash', $hash)
                    ->orWhere('alias', $alias);
            })->first();
    }

    public function delete(Document $document): bool
    {
        return $document->delete();
    }
}
