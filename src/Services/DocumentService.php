<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SimoneBianco\LaravelRagChunks\Drivers\Embedding\Contracts\EmbeddingDriverInterface;
use SimoneBianco\LaravelRagChunks\DTOs\DocumentDTO;
use SimoneBianco\LaravelRagChunks\DTOs\DocumentSearchDataDTO;
use SimoneBianco\LaravelRagChunks\Factories\EmbeddingFactory;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Search;

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
                'hash' => $dto->hash ?? \SimoneBianco\LaravelRagChunks\Facades\HashService::hash($dto->text),
                'name' => $dto->name ?? Str::limit($dto->text),
                'name_embedding' => $dto->name ? $this->embeddingDriver->embed($dto->name) : null,
                'description' => $dto->description ?? Str::limit($dto->text),
                'description_embedding' => $dto->description ? $this->embeddingDriver->embed($dto->description) : null,
                'metadata' => $dto->metadata,
            ]);

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

        $document->tags()->delete();

        if ($dto->tagsByType->isNotEmpty()) {
            $dto->tagsByType->each(fn (array $value, string $key) => $document->attachTags($value, $key));
        }

        if (!empty($dto->typelessTags)) {
            $document->attachTags($dto->typelessTags);
        }

        return $document;
    }

    public function regenerateChunks(DocumentDTO $documentData, array $rawChunks): Document
    {
        $rawChunksData = array_map(function ($rawChunk) {
            return [
                'content' => $rawChunk,
                'hash' => \SimoneBianco\LaravelRagChunks\Facades\HashService::hash($rawChunk),
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
                \Illuminate\Support\Facades\Log::info("DocumentService: Embedding chunk index {$index}");
                try {
                    $embedding = $this->embeddingDriver->embed($data['content']);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("DocumentService: Embedding failed for chunk {$index}. Error: " . $e->getMessage());
                    throw $e;
                }
                
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
                    if (!empty($documentData->typelessTags)) {
                        $chunk->attachTags($documentData->typelessTags);
                    }

                    if ($documentData->tagsByType->isNotEmpty()) {
                        $documentData->tagsByType->each(
                            fn (array $value, string $key) => $chunk->attachTags($value, $key)
                        );
                    }
                });

                return $document;
            });
    }

    public function search(DocumentSearchDataDTO $searchData)
    {
        return $this->documentModel::select('*')
            ->with('tags')
            ->when(!empty($searchData->aliases), function (Builder $query) use ($searchData) {
                $query->whereIn('alias', $searchData->aliases);
            })->when(!empty($searchData->name), function (Builder $query) use ($searchData) {
                $nameEmbedding = Search::embed($searchData->name);
                $vector = '[' . implode(',', $nameEmbedding) . ']';

                $query->nearestNeighbors('name_embedding', $nameEmbedding, 'cosine')
                    ->selectRaw('1 - (name_embedding <=> ?) as name_similarity', [$vector])
                    ->selectRaw('1 - (name_embedding <=> ?) as similarity', [$vector]);
            })->when(!empty($searchData->anyTags), function (Builder $query) use ($searchData) {
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
            })->when(!empty($searchData->description), function (Builder $query) use ($searchData) {
                $descriptionEmbedding = Search::embed($searchData->description);
                $vector = '[' . implode(',', $descriptionEmbedding) . ']';

                $query->nearestNeighbors('description_embedding', $descriptionEmbedding, 'cosine')
                    ->selectRaw('1 - (description_embedding <=> ?) as description_similarity', [$vector])
                    ->selectRaw('1 - (description_embedding <=> ?) as similarity', [$vector]);
            })->paginate(
                $searchData->perPage,
                ['*'],
                'page',
                $searchData->page
            );
    }

    public function delete(Document $document): bool
    {
        return $document->delete();
    }
}
