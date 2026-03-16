<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\AiAgents\GenerateDocumentMetadataAgent;
use SimoneBianco\LaravelRagChunks\DTOs\DocumentSearchDataDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\PostProcessedItemDTO;
use SimoneBianco\LaravelRagChunks\Enums\TagFilterMode;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;
use SimoneBianco\LaravelRagChunks\Jobs\GenerateDocumentMetadataJob;
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
    public function regeneratePostProcessedChunks(Document $document, string $relativeJsonlPath, array $options = []): Document
    {
        if (!$this->fileService->exists($relativeJsonlPath)) {
            throw new FileNotFoundException("JSONL file not found at $relativeJsonlPath");
        }

        $keepChunksWithImages = (bool) ($options['keep_chunks_with_images'] ?? false);
        $now = now();

        if ($keepChunksWithImages) {
            $document->chunks()
                ->where(function (Builder $query) {
                    $query->where('is_image', false)
                        ->orWhereNull('is_image');
                })
                ->whereDoesntHave('dedupMedia')
                ->delete();
        } else {
            $document->purgeChunks();
        }

        $maxExistingOrder = (int) ($document->chunks()->max('order') ?? 0);
        $readStream = $this->fileService->readStream($relativeJsonlPath);
        $chunksBuffer = [];
        $figuresBuffer = [];
        $deterministicTagsBuffer = []; // chunkId => {typeAlias => [slugs]}
        $index = $maxExistingOrder + 1;
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

            if (!empty($data->deterministicTags)) {
                $deterministicTagsBuffer[$id] = $data->deterministicTags;
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
                'chapter' => $data->chapter,
            ];

            $chunksBuffer[] = $data;
            if (count($chunksBuffer) < 10) {
                continue;
            }

            DB::transaction(function () use ($chunksBuffer, $figuresBuffer, $document) {
                Chunk::insert($chunksBuffer);

                if (!empty($figuresBuffer)) {
                    $this->attachFiguresToChunks($figuresBuffer);
                }
            });

            // Update denormalized cache column
            $this->updateDocumentIndexedCache($document);

            if (!empty($deterministicTagsBuffer)) {
                $this->attachDeterministicTagsToChunks($document, $deterministicTagsBuffer);
            }

            $chunksBuffer = [];
            $figuresBuffer = [];
            $deterministicTagsBuffer = [];
        }

        if (!empty($chunksBuffer)) {
            DB::transaction(function () use ($chunksBuffer, $figuresBuffer, $document) {
                Chunk::insert($chunksBuffer);

                if (!empty($figuresBuffer)) {
                    $this->attachFiguresToChunks($figuresBuffer);
                }
            });

            if (!empty($deterministicTagsBuffer)) {
                $this->attachDeterministicTagsToChunks($document, $deterministicTagsBuffer);
            }

            // Update denormalized cache column
            $this->updateDocumentIndexedCache($document);
        }

        // Apply inherited tags from document metadata (set at parse-time via ParseDocumentModal)
        $metadata = $document->fresh()->metadata ?? [];
        $inheritedClassicTags = $metadata['chunk_classic_tags'] ?? [];
        $inheritedSemanticTags = $metadata['chunk_semantic_tags'] ?? [];

        if (!empty($inheritedClassicTags)) {
            $this->attachInheritedClassicTagsToAllChunks($document, $inheritedClassicTags);
        }
        if (!empty($inheritedSemanticTags)) {
            $this->mergeSemanticTagsToAllChunks($document, $inheritedSemanticTags);
        }

        return $document;
    }

    /**
     * Apply inherited classic tags (from document metadata) to ALL chunks of the document.
     * Input format: { tagTypeId (int or string) => [tagIds] }
     */
    protected function attachInheritedClassicTagsToAllChunks(Document $document, array $tagsByTypeId): void
    {
        $allTagIds = array_values(array_unique(array_merge(...array_values(array_filter($tagsByTypeId)))));
        if (empty($allTagIds)) {
            return;
        }

        $chunkIds = Chunk::where('document_id', $document->id)->pluck('id')->toArray();
        if (empty($chunkIds)) {
            return;
        }

        $taggableTable = config('tags.taggable.table_name', 'taggables');
        $morphName = config('tags.taggable.morph_name', 'taggable');
        $morphType = (new Chunk)->getMorphClass();

        $rows = [];
        foreach ($chunkIds as $chunkId) {
            foreach ($allTagIds as $tagId) {
                $rows[] = [
                    'tag_id'             => $tagId,
                    "{$morphName}_id"    => $chunkId,
                    "{$morphName}_type"  => $morphType,
                ];
            }
        }

        // Batch insert to avoid huge single INSERT; insertOrIgnore for idempotency
        foreach (array_chunk($rows, 500) as $batch) {
            DB::table($taggableTable)->insertOrIgnore($batch);
        }
    }

    /**
     * Merge inherited semantic tags into the `tags` JSON column of ALL document chunks.
     * Uses array_unique so existing tags are never duplicated.
     */
    protected function mergeSemanticTagsToAllChunks(Document $document, array $semanticTags): void
    {
        if (empty($semanticTags)) {
            return;
        }

        Chunk::where('document_id', $document->id)
            ->select(['id', 'tags'])
            ->chunkById(200, function ($chunks) use ($semanticTags) {
                foreach ($chunks as $chunk) {
                    $existing = $chunk->tags ?? [];
                    $merged = array_values(array_unique(array_merge($existing, $semanticTags)));
                    if ($merged !== $existing) {
                        Chunk::where('id', $chunk->id)->update(['tags' => json_encode($merged, JSON_UNESCAPED_UNICODE)]);
                    }
                }
            });
    }

    /**
     * Attach deterministic tags (from AI assignment) to their chunks via the taggables table.
     *
     * @param Document $document
     * @param array<string, array<string, string[]>> $tagMap  chunkId => {typeAlias => [slugs]}
     */
    protected function attachDeterministicTagsToChunks(Document $document, array $tagMap): void
    {
        $tagTypeModel = config('tags.tag_type_model', \SimoneBianco\LaravelSimpleTags\TagType::class);
        $tagModel = config('tags.tag_model', \SimoneBianco\LaravelSimpleTags\Tag::class);

        $aliases = array_unique(array_merge(...array_map(fn ($t) => array_keys($t), array_values($tagMap))));

        $typeAliasToId = $tagTypeModel::where('project_id', $document->project_id)
            ->whereIn('alias', $aliases)
            ->pluck('id', 'alias')
            ->toArray();

        if (empty($typeAliasToId)) {
            return;
        }

        // Collect all slugs needed per type
        $slugsByTypeId = [];
        foreach ($tagMap as $deterministicTags) {
            foreach ($deterministicTags as $alias => $slugs) {
                $typeId = $typeAliasToId[$alias] ?? null;
                if ($typeId) {
                    $slugsByTypeId[$typeId] = array_unique(array_merge($slugsByTypeId[$typeId] ?? [], $slugs));
                }
            }
        }

        // Load tag id maps per type
        $tagIdMap = []; // {typeId => {slug => tagId}}
        foreach ($slugsByTypeId as $typeId => $slugs) {
            $tagIdMap[$typeId] = $tagModel::where('tag_type_id', $typeId)
                ->whereIn('slug', $slugs)
                ->pluck('id', 'slug')
                ->toArray();
        }

        // Build taggables rows
        $taggableRows = [];
        $taggableTable = config('tags.taggable.table_name', 'taggables');
        $morphName = config('tags.taggable.morph_name', 'taggable');
        $morphType = (new Chunk)->getMorphClass();

        foreach ($tagMap as $chunkId => $deterministicTags) {
            foreach ($deterministicTags as $alias => $slugs) {
                $typeId = $typeAliasToId[$alias] ?? null;
                if (!$typeId) {
                    continue;
                }
                foreach ($slugs as $slug) {
                    $tagId = $tagIdMap[$typeId][$slug] ?? null;
                    if ($tagId) {
                        $taggableRows[] = [
                            'tag_id'             => $tagId,
                            "{$morphName}_id"    => $chunkId,
                            "{$morphName}_type"  => $morphType,
                        ];
                    }
                }
            }
        }

        if (!empty($taggableRows)) {
            DB::table($taggableTable)->insertOrIgnore($taggableRows);
        }
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
     * @param array{
     *     generate_name?: bool,
     *     generate_description?: bool,
     *     generate_classic_tags?: bool,
     *     generate_semantic_tags?: bool,
     *     generate_questions?: bool
     * } $options
     */
    public function generateMetadata(Document $document, array $options): Process
    {
        if ($document->hasActiveProcesses()) {
            throw new RuntimeException('Cannot generate metadata while another process is active.');
        }

        $resolvedOptions = $this->resolveMetadataGenerationOptions($options);

        if (! $this->shouldGenerateAnyMetadata($resolvedOptions)) {
            throw new RuntimeException('No metadata field selected for generation.');
        }

        $process = $document->startProcess('document_metadata_generation', [
            'options' => $resolvedOptions,
            'phase' => 'queued',
            'is_retryable' => false,
        ]);

        GenerateDocumentMetadataJob::dispatch($process->id);

        return $process;
    }

    /**
     * @param array{
     *     generate_name?: bool,
     *     generate_description?: bool,
     *     generate_classic_tags?: bool,
     *     generate_semantic_tags?: bool,
     *     generate_questions?: bool
     * } $options
     * @param array<string, array<int, string>> $availableClassicTagsByType
     * @param array<int, string> $availableTagTypes
     * @return array<string, mixed>
     */
    public function generateMetadataPayload(
        Document $document,
        array $options,
        array $availableClassicTagsByType = [],
        array $availableTagTypes = [],
    ): array
    {
        $resolvedOptions = $this->resolveMetadataGenerationOptions($options);

        if (! $this->shouldGenerateAnyMetadata($resolvedOptions)) {
            throw new RuntimeException('No metadata field selected for generation.');
        }

        $content = $this->getDocumentContentSnippetForMetadata($document);

        if ($content === '') {
            throw new RuntimeException('Unable to extract a usable content snippet from the document.');
        }

        $agent = (new GenerateDocumentMetadataAgent(uniqid('doc-meta-', true)))
            ->withCurrentName((string) ($document->name ?? ''))
            ->withCurrentDescription((string) ($document->description ?? ''))
            ->withDocumentContent($content)
            ->withAvailableTagTypes($availableTagTypes)
            ->withAvailableClassicTagsByType($availableClassicTagsByType)
            ->withGenerateName((bool) ($resolvedOptions['generate_name'] ?? false))
            ->withGenerateDescription((bool) ($resolvedOptions['generate_description'] ?? false))
            ->withGenerateClassicTags((bool) ($resolvedOptions['generate_classic_tags'] ?? false))
            ->withGenerateSemanticTags((bool) ($resolvedOptions['generate_semantic_tags'] ?? false))
            ->withGenerateQuestions((bool) ($resolvedOptions['generate_questions'] ?? false));

        $response = $agent->respond();

        return is_array($response) ? $response : [];
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array{
     *     generate_name?: bool,
     *     generate_description?: bool,
     *     generate_classic_tags?: bool,
     *     generate_semantic_tags?: bool,
     *     generate_questions?: bool
     * } $options
     * @return array<string, mixed>
     */
    public function applyGeneratedMetadata(Document $document, array $metadata, array $options): array
    {
        $resolvedOptions = $this->resolveMetadataGenerationOptions($options);

        $updates = [];
        $applied = [];

        if (($resolvedOptions['generate_name'] ?? false) && array_key_exists('name', $metadata)) {
            $name = trim((string) ($metadata['name'] ?? ''));
            if ($name !== '') {
                $updates['name'] = mb_substr($name, 0, 255);
                $applied['name'] = $updates['name'];
            }
        }

        if (($resolvedOptions['generate_description'] ?? false) && array_key_exists('description', $metadata)) {
            $description = trim((string) ($metadata['description'] ?? ''));
            if ($description !== '') {
                $updates['description'] = $description;
                $applied['description'] = $description;
            }
        }

        if (($resolvedOptions['generate_semantic_tags'] ?? false) && array_key_exists('semantic_tags', $metadata)) {
            $semanticTags = $this->normalizeStringList(is_array($metadata['semantic_tags']) ? $metadata['semantic_tags'] : [], 255);
            $updates['semantic_tags'] = $semanticTags;
            $applied['semantic_tags'] = $semanticTags;
        }

        if (($resolvedOptions['generate_questions'] ?? false) && array_key_exists('questions', $metadata)) {
            $questions = $this->normalizeStringList(is_array($metadata['questions']) ? $metadata['questions'] : [], 1000);
            $updates['questions'] = $questions;
            $applied['questions'] = $questions;
        }

        if (! empty($updates)) {
            $document->update($updates);
        }

        if (($resolvedOptions['generate_classic_tags'] ?? false) && array_key_exists('classic_tags', $metadata)) {
            $classicTags = $this->normalizeClassicTags(is_array($metadata['classic_tags']) ? $metadata['classic_tags'] : []);
            [$tagIds, $tagIdsByType] = $this->resolveClassicTagIdsByType($document, $classicTags);
            $document->tags()->sync($tagIds);

            $applied['classic_tags'] = $classicTags;
            $applied['classic_tags_by_type'] = $tagIdsByType;
        }

        return $applied;
    }

    /**
     * @param array{
     *     generate_name?: bool,
     *     generate_description?: bool,
     *     generate_classic_tags?: bool,
     *     generate_semantic_tags?: bool,
     *     generate_questions?: bool
     * } $options
     * @return array{
     *     generate_name: bool,
     *     generate_description: bool,
     *     generate_classic_tags: bool,
     *     generate_semantic_tags: bool,
     *     generate_questions: bool
     * }
     */
    protected function resolveMetadataGenerationOptions(array $options): array
    {
        return [
            'generate_name' => (bool) ($options['generate_name'] ?? true),
            'generate_description' => (bool) ($options['generate_description'] ?? true),
            'generate_classic_tags' => (bool) ($options['generate_classic_tags'] ?? true),
            'generate_semantic_tags' => (bool) ($options['generate_semantic_tags'] ?? true),
            'generate_questions' => (bool) ($options['generate_questions'] ?? true),
        ];
    }

    /**
     * @param array{
     *     generate_name: bool,
     *     generate_description: bool,
     *     generate_classic_tags: bool,
     *     generate_semantic_tags: bool,
     *     generate_questions: bool
     * } $options
     */
    protected function shouldGenerateAnyMetadata(array $options): bool
    {
        return in_array(true, [
            $options['generate_name'],
            $options['generate_description'],
            $options['generate_classic_tags'],
            $options['generate_semantic_tags'],
            $options['generate_questions'],
        ], true);
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    protected function normalizeStringList(array $values, int $maxLength): array
    {
        $normalized = [];
        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $item = trim((string) $value);
            if ($item === '') {
                continue;
            }

            $item = mb_substr($item, 0, $maxLength);
            $key = Str::lower($item);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $classicTags
     * @return array<int, array{type: string, tags: array<int, string>}>
     */
    protected function normalizeClassicTags(array $classicTags): array
    {
        $normalized = [];

        foreach ($classicTags as $group) {
            if (! is_array($group)) {
                continue;
            }

            $type = trim((string) ($group['type'] ?? ''));
            $tags = $this->normalizeStringList(is_array($group['tags'] ?? null) ? $group['tags'] : [], 100);

            if ($type === '' || empty($tags)) {
                continue;
            }

            $normalized[] = [
                'type' => $type,
                'tags' => $tags,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, array{type: string, tags: array<int, string>}> $classicTags
     * @return array{0: array<int, mixed>, 1: array<int|string, array<int, mixed>>}
     */
    protected function resolveClassicTagIdsByType(Document $document, array $classicTags): array
    {
        if (empty($classicTags)) {
            return [[], []];
        }

        $tagTypeModel = config('tags.tag_type_model', \SimoneBianco\LaravelSimpleTags\TagType::class);
        $tagModel = config('tags.tag_model', \SimoneBianco\LaravelSimpleTags\Tag::class);

        $tagTypes = $tagTypeModel::query()
            ->where('project_id', $document->project_id)
            ->get(['id', 'alias', 'label']);

        if ($tagTypes->isEmpty()) {
            return [[], []];
        }

        $typeLookup = [];
        $typeIds = [];

        foreach ($tagTypes as $tagType) {
            $typeIds[] = $tagType->id;

            $alias = Str::slug((string) ($tagType->alias ?? ''));
            $label = Str::slug((string) ($tagType->label ?? ''));

            if ($alias !== '') {
                $typeLookup[$alias] = $tagType->id;
            }
            if ($label !== '') {
                $typeLookup[$label] = $tagType->id;
            }
        }

        $tagRecords = $tagModel::query()
            ->whereIn('tag_type_id', $typeIds)
            ->get(['id', 'tag_type_id', 'name', 'slug']);

        $tagLookupByType = [];
        foreach ($tagRecords as $tagRecord) {
            $typeId = $tagRecord->tag_type_id;
            $slug = Str::slug((string) ($tagRecord->slug ?? ''));
            $name = Str::slug((string) ($tagRecord->name ?? ''));

            if ($slug !== '') {
                $tagLookupByType[$typeId][$slug] = $tagRecord->id;
            }
            if ($name !== '') {
                $tagLookupByType[$typeId][$name] = $tagRecord->id;
            }
        }

        $tagIdsByType = [];
        foreach ($classicTags as $group) {
            $typeKey = Str::slug($group['type']);
            $typeId = $typeLookup[$typeKey] ?? null;
            if (! $typeId) {
                continue;
            }

            foreach ($group['tags'] as $tagName) {
                $tagKey = Str::slug($tagName);
                $tagId = $tagLookupByType[$typeId][$tagKey] ?? null;
                if ($tagId) {
                    $tagIdsByType[$typeId][] = $tagId;
                }
            }
        }

        foreach ($tagIdsByType as $typeId => $tagIds) {
            $tagIdsByType[$typeId] = array_values(array_unique($tagIds));
        }

        $allTagIdsMap = [];
        foreach ($tagIdsByType as $tagIds) {
            foreach ($tagIds as $tagId) {
                $allTagIdsMap[(string) $tagId] = $tagId;
            }
        }

        $allTagIds = array_values($allTagIdsMap);

        return [$allTagIds, $tagIdsByType];
    }

    protected function getDocumentContentSnippetForMetadata(Document $document): string
    {
        $chunkContent = trim($this->getDocumentChunkSnippetForMetadata($document));
        if ($chunkContent !== '') {
            return mb_substr($chunkContent, 0, 12000);
        }

        if ($document->file_path === null || trim($document->file_path) === '' || ! $this->fileService->exists($document->file_path)) {
            return '';
        }

        $content = $this->fileService->get($document->file_path);

        return mb_substr(trim((string) $content), 0, 12000);
    }

    protected function getDocumentChunkSnippetForMetadata(Document $document): string
    {
        $limit = 12000;
        $buffer = '';

        $chunks = $document->chunks()
            ->select('content')
            ->whereNotNull('content')
            ->orderBy('order')
            ->limit(50)
            ->get();

        foreach ($chunks as $chunk) {
            $content = trim((string) ($chunk->content ?? ''));
            if ($content === '') {
                continue;
            }

            $buffer = trim($buffer . ' ' . $content);

            if (mb_strlen($buffer) >= $limit) {
                break;
            }
        }

        return mb_substr($buffer, 0, $limit);
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

    /**
     * Update the cached_has_indexed_chunks column for a document.
     * Called after bulk chunk operations to ensure the cache stays in sync.
     */
    protected function updateDocumentIndexedCache(Document $document): void
    {
        $hasIndexedChunks = $document->chunks()
            ->whereNotNull('chapter')
            ->where('chapter', '!=', '')
            ->exists();

        $document->updateQuietly([
            'cached_has_indexed_chunks' => $hasIndexedChunks,
            'cached_at' => now(),
        ]);
    }
}
