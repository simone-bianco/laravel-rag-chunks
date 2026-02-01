<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SimoneBianco\LaravelRagChunks\DTOs\DocumentDTO;
use SimoneBianco\LaravelRagChunks\DTOs\DocumentSearchDataDTO;
use SimoneBianco\LaravelRagChunks\Enums\TagFilterMode;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Embedding;
use SimoneBianco\LaravelRagChunks\Facades\HashService;
use SimoneBianco\LaravelRagChunks\Models\Project;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use Throwable;

class DocumentService
{
    /**
     * @throws Throwable
     */
    public function getOrCreateDocument(DocumentDTO $dto): Document
    {
        return DB::transaction(function () use ($dto) {
            /** @var Document $document */
            $document = Document::query()
                ->firstOrCreate([
                    'alias' => $dto->alias ?? Project::where('id', $dto->project_id)->firstOrFail()->alias . '-' . Str::uuid()->toString(),
                ], [
                    'project_id' => $dto->project_id,
                    'file_path' => $dto->filePath,
                    'hash' => $dto->hash ?? HashService::hash($dto->text),
                    'name' => $dto->name,
                    'description' => $dto->description,
                    'metadata' => $dto->metadata,
                ]);

            $document->project_id = $dto->project_id;
            $document->name = $dto->name ?? $document->name;
            $document->description = $dto->description ?? $document->description;
            if ($document->isDirty('name') || $document->wasRecentlyCreated) {
                $document->name_embedding = $document->name ? Embedding::embed($document->name) : null;
            }
            if ($document->isDirty('description') || $document->wasRecentlyCreated) {
                $document->description_embedding = $document->description ? Embedding::embed($document->description) : null;
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
        });
    }

    public function regenerateChunks(DocumentDTO $documentData): Document
    {
        $document = $this->getOrCreateDocument($documentData);
        $parser = DocumentParserFactory::make($document->extension);

        foreach ($parser->chunkDocument($document->file_path) as $chunks) {
            $existingChunks = Chunk::query()
                ->select(['hash', 'embedding'])
                ->distinct()
                ->whereIn('hash', Arr::pluck($chunks, 'hash'), 'hash')
                ->get()
                ->keyBy('hash');

            foreach ($chunks as $chunk) {

            }
        }

        $rawChunksData = array_map(function ($rawChunk) {
            return [
                'content' => $rawChunk,
                'hash' => HashService::hash($rawChunk),
            ];
        }, $rawChunks);

        /** @var Collection<string, Chunk> $existingChunks */
        $existingChunks = Chunk::query()
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
                $chunk = (new Chunk)->fill([
                    'content' => $existingChunk->content,
                    'hash' => $existingChunk->hash,
                    'embedding' => $existingChunk->embedding,
                ]);
            } else {
                $embedding = Embedding::embed($data['content']);

                $chunk = (new Chunk)->fill([
                    'content' => $data['content'],
                    'hash' => $hash,
                    'embedding' => $embedding,
                ]);
            }

            $chunk->order = $index + 1;
            $chunk->page = $index + 1;
            $createdChunks->push($chunk);
        }

        return Document::query()
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
        $query = Document::select('*')
            ->where('enabled', true)
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
        return Document::where('project_id', $projectId)
            ->where(function ($q) use ($hash, $alias) {
                $q->where('hash', $hash)->orWhere('alias', $alias);
            })->first();
    }

    public function delete(Document $document): bool
    {
        return $document->delete();
    }

    /**
     * @param Document $document
     * @param int $targetOrder
     * @param array{content: string, embedding?: array} $data
     * @return Chunk
     * @throws Throwable
     */
    public function insertChunk(Document $document, int $targetOrder, array $data): Chunk
    {
        return DB::transaction(function () use ($document, $targetOrder, $data) {
            // Shift subsequent chunks
            $document->chunks()
                ->where('order', '>=', $targetOrder)
                ->increment('order');

            // Create new chunk
            $chunk = new Chunk();
            $chunk->document_id = $document->id;
            $chunk->fill($data);
            $chunk->order = $targetOrder;
            $chunk->page = $document->chunks()->where('order', '<', $targetOrder)->max('page') ?? 1; // Best guess for page
            $chunk->hash = HashService::hash($data['content']);
            
            if (!isset($data['embedding'])) {
                 $chunk->embedding = Embedding::embed($data['content']);
            }

            $chunk->save();

            return $chunk;
        });
    }
}
