<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SimoneBianco\LaravelRagChunks\DTOs\DocumentDTO;
use SimoneBianco\LaravelRagChunks\DTOs\DocumentSearchDataDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\PostProcessedItemDTO;
use SimoneBianco\LaravelRagChunks\Enums\TagFilterMode;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Embedding;
use SimoneBianco\LaravelRagChunks\Facades\HashService;
use SimoneBianco\LaravelRagChunks\Models\Project;
use Throwable;

class DocumentService
{
    public function __construct(
        protected StreamService $streamService,
        protected null|Filesystem|LocalFilesystemAdapter $storage = null,
    ) {
        $this->storage = $storage ?? Storage::disk('local');
    }

    protected function storage(): Filesystem|LocalFilesystemAdapter
    {
        return Storage::disk('local');
    }

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
                    'disk' => $dto->disk,
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

    /**
     * @param Document $document
     * @param string $relativeJsonlPath
     * @return Document
     * @throws FileNotFoundException
     * @throws Throwable
     */
    public function regeneratePostProcessedChunks(Document $document, string $relativeJsonlPath): Document
    {
        if (!$this->storage()->exists($relativeJsonlPath)) {
            throw new FileNotFoundException("JSONL file not found at $relativeJsonlPath");
        }

        $now = now();
        $document->purgeChunks();
        $readStream = $this->storage()->readStream($relativeJsonlPath);
        $chunksBuffer = [];
        $figuresBuffer = [];
        $index = 1;
        while (($line = fgets($readStream)) !== false) {
            $data = PostProcessedItemDTO::fromArray(json_decode($line, true));

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }

            $id = Str::uuid()->toString();

            if ($figurePath = $data->figurePath) {
                $figuresBuffer[$id] = $figurePath;
            }

            $data = [
                'id' => $id,
                'document_id' => $document->id,
                'created_at'  => $now,
                'updated_at'  => $now,
                'content' => $data->text,
                'hash' => $data->textHash,
                'embedding' => !empty($data->textEmbedding) ? (is_string($data->textEmbedding) ? $data->textEmbedding : json_encode($data->textEmbedding)) : null,
                'tags' => $data->tags,
                'tags_embedding' => !empty($data->tagsEmbedding) ? (is_string($data->tagsEmbedding) ? $data->tagsEmbedding : json_encode($data->tagsEmbedding)) : null,
                'order' => $index++,
                'is_image' => !!$data->figurePath,
                'questions' => $data->questions,
                'questions_embedding' => !empty($data->questionsEmbedding) ? (is_string($data->questionsEmbedding) ? $data->questionsEmbedding : json_encode($data->questionsEmbedding)) : null,
            ];

            $chunksBuffer[] = $data;
            if (count($chunksBuffer) < 2) {
                continue;
            }

            DB::transaction(function () use ($chunksBuffer, $figuresBuffer) {
                Chunk::insert($chunksBuffer);

                Chunk::select(['id'])
                    ->whereIn('id', Arr::pluck($figuresBuffer, 'id'))
                    ->get()
                    ->each(function (Chunk $chunk) use ($figuresBuffer) {
                        $chunk->attachMediaFromPath($figuresBuffer[$chunk->id]);
                    });
            });
            $chunksBuffer = [];
            $figuresBuffer = [];
        }

        if (count($chunksBuffer) >= 2) {
            Chunk::insert($chunksBuffer);
        }

        return $document;
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
            $document->chunks()
                ->where('order', '>=', $targetOrder)
                ->increment('order');

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
    /**
     * @param string $absoluteFilePath
     * @return string
     * @throws FileNotFoundException
     */
    public function calculateFileHash(string $absoluteFilePath): string
    {
        try {
            return HashService::hashFile($absoluteFilePath);
        } catch (\RuntimeException $e) {
             throw new FileNotFoundException($e->getMessage());
        }
    }
}
