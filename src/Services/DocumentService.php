<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SimoneBianco\LaravelRagChunks\DTOs\DocumentSearchDataDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\PostProcessedItemDTO;
use SimoneBianco\LaravelRagChunks\Enums\TagFilterMode;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;
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
        protected FileService $fileService
    ) {}

    /**
     * @param Project $project
     * @param string $absolutePath
     * @param array $extraData
     * @return Document
     * @throws FileNotFoundException
     * @throws InvalidFileException
     */
    public function createByAbsolutePath(Project $project, string $absolutePath, array $extraData = []): Document
    {
        $relativePath = $this->fileService->saveFileByAbsolutePath($absolutePath);

        return $this->createByPath($project, $relativePath, $extraData);
    }

    /**
     * @param Project $project
     * @param string $relativePath
     * @param array $extraData
     * @return Document|\Illuminate\Database\Eloquent\Model
     * @throws FileNotFoundException
     */
    public function createByPath(Project $project, string $relativePath, array $extraData = []): Document
    {
        $documentName = pathinfo($relativePath, PATHINFO_FILENAME);

        return $project->documents()->create([
            'name' => pathinfo($relativePath, PATHINFO_FILENAME),
            'extension' => pathinfo($relativePath, PATHINFO_EXTENSION),
            'alias' => now()->timestamp . '-' . strtolower(Str::slug($documentName)),
            'disk' => $this->fileService->getDisk(),
            'hash' => $this->calculateFileHash($this->fileService->getAbsolutePath($relativePath)),
            'file_path' => $relativePath,
            ...$extraData
        ]);
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
        if (!$this->fileService->exists($relativeJsonlPath)) {
            throw new FileNotFoundException("JSONL file not found at $relativeJsonlPath");
        }

        $now = now();
        $document->purgeChunks();
        $readStream = $this->fileService->readStream($relativeJsonlPath);
        $chunksBuffer = [];
        $figuresBuffer = [];
        $index = 1;
        while (($line = fgets($readStream)) !== false) {
            $line = str_replace(["\u{0000}", '\\u0000'], '', $line);
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
                'tags' => !empty($data->tags) ? json_encode($data->tags, JSON_UNESCAPED_UNICODE) : null,
                'tags_embedding' => !empty($data->tagsEmbedding) ? (is_string($data->tagsEmbedding) ? $data->tagsEmbedding : json_encode($data->tagsEmbedding)) : null,
                'order' => $index++,
                'is_image' => !!$data->figurePath,
                'questions' => !empty($data->questions) ? json_encode($data->questions, JSON_UNESCAPED_UNICODE) : null,
                'questions_embedding' => !empty($data->questionsEmbedding) ? (is_string($data->questionsEmbedding) ? $data->questionsEmbedding : json_encode($data->questionsEmbedding)) : null,
            ];

            $chunksBuffer[] = $data;
            if (count($chunksBuffer) < 10) {
                continue;
            }

            DB::transaction(function () use ($chunksBuffer, $figuresBuffer) {
                Chunk::insert($chunksBuffer);

                if (!empty($figuresBuffer)) {
                    $this->attachFiguresToChunks($figuresBuffer);
                }
            });
            $chunksBuffer = [];
            $figuresBuffer = [];
        }

        if (!empty($chunksBuffer)) {
            DB::transaction(function () use ($chunksBuffer, $figuresBuffer) {
                Chunk::insert($chunksBuffer);

                if (!empty($figuresBuffer)) {
                    $this->attachFiguresToChunks($figuresBuffer);
                }
            });
        }

        return $document;
    }

    /**
     * Attach figure images to their chunks, resolving relative paths to absolute.
     *
     * @param array<string, string> $figuresBuffer Map of chunk ID => relative figure path
     */
    protected function attachFiguresToChunks(array $figuresBuffer): void
    {
        Chunk::select(['id'])
            ->whereIn('id', array_keys($figuresBuffer))
            ->get()
            ->each(function (Chunk $chunk) use ($figuresBuffer) {
                $absolutePath = $this->fileService->getAbsolutePath($figuresBuffer[$chunk->id]);
                if (file_exists($absolutePath)) {
                    $chunk->attachMediaFromPath($absolutePath);
                }
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
        } catch (RuntimeException $e) {
             throw new FileNotFoundException($e->getMessage());
        }
    }
}
