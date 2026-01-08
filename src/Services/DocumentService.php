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
        $document = $this->documentModel::query()
            ->firstOrCreate([
                'alias' => $dto->alias ?? Str::random(64),
            ], [
                'hash' => $dto->hash ?? \SimoneBianco\LaravelRagChunks\Facades\HashService::hash($dto->text),
                'name' => $dto->name ?? Str::limit($dto->text),
                'name_embedding' => $dto->name ? $this->embeddingDriver->embed($dto->name) : null,
                'description' => $dto->description ?? Str::limit($dto->text),
                'description_embedding' => $dto->description ? $this->embeddingDriver->embed($dto->description) : null,
                'metadata' => $dto->metadata,
            ]);

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
                $chunk = (new $this->chunkModel)->fill([
                    'content' => $data['content'],
                    'hash' => $hash,
                    'embedding' => $this->embeddingDriver->embed($data['content']),
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
        return $this->documentModel::with('tags')
            ->when(!empty($searchData->alias), function (Builder $query) use ($searchData) {
                $query->where('alias', 'like', "%{$searchData->alias}%");
            })->when(!empty($searchData->name), function (Builder $query) use ($searchData) {
                $nameEmbedding = Search::embed($searchData->name);
                $vector = '[' . implode(',', $nameEmbedding) . ']';

                $query->nearestNeighbors('name_embedding', $nameEmbedding, 'cosine')
                    ->selectRaw('1 - (name_embedding <=> ?) as name_similarity', [$vector])
                    ->selectRaw('1 - (name_embedding <=> ?) as similarity', [$vector]);
            })->when(!empty($searchData->tags), function (Builder $query) use ($searchData) {
                if ($searchData->tagFilterMode === TagFilterMode::ALL) {
                    $query->withAllTagsOfAnyType($searchData->tags);
                } else {
                    $query->withAnyTagsOfAnyType($searchData->tags);
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
