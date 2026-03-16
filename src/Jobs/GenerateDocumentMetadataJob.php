<?php

namespace SimoneBianco\LaravelRagChunks\Jobs;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Events\DocumentMetadataGeneratedEvent;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\DocumentService;
use SimoneBianco\LaravelSimpleTags\TagType;
use Throwable;

class GenerateDocumentMetadataJob extends BaseProcessJob
{
    public int $tries = 1;

    public int $timeout = 900;

    protected function getJobName(): string
    {
        return 'document_metadata_generation';
    }

    public function uniqueId(): string
    {
        return $this->processId;
    }

    protected function logger(): LoggerInterface
    {
        return Log::channel('document-queue');
    }

    protected function enrichContext(array $extra = []): void
    {
        Context::add([
            'laravel_job' => $this->getJobName(),
            'process_id' => $this->processId ?? null,
            'trial' => $this->attempts(),
            ...$extra,
        ]);
    }

    public function __construct(protected string $processId) {}

    public function handle(DocumentService $documentService): void
    {
        $document = null;

        try {
            $this->enrichContext();

            /** @var Process $process */
            $process = Process::with('processable')->findOrFail($this->processId);

            /** @var Document $document */
            $document = $process->processable;
            $this->enrichContext(['document_id' => $document->id]);

            $process->setProcessing(['phase' => 'generating_metadata']);

            $options = $process->getContext('options', []);
            if (! is_array($options)) {
                $options = [];
            }

            $availableClassicTagsByType = [];
            $availableTagTypes = [];
            if ((bool) ($options['generate_classic_tags'] ?? true)) {
                $availableTagTypes = $this->loadAvailableTagTypes($document);
                $availableClassicTagsByType = $this->loadAvailableClassicTagsByType($document, $availableTagTypes);
            }

            $metadata = $documentService->generateMetadataPayload($document, $options, $availableClassicTagsByType, $availableTagTypes);
            
            Log::channel('document-queue')->info('[GenerateMetadataJob] Generated metadata', [
                'document_id' => $document->id,
                'metadata_name' => $metadata['name'] ?? null,
                'has_classic_tags' => !empty($metadata['classic_tags_by_type']),
                'semantic_tags_count' => count($metadata['semantic_tags'] ?? []),
                'questions_count' => count($metadata['questions'] ?? []),
            ]);
            
            $appliedMetadata = $documentService->applyGeneratedMetadata($document, $metadata, $options);

            $this->flushMetadataCachesBeforeBroadcast($document);

            Log::channel('document-queue')->info('[GenerateMetadataJob] Applied metadata to document', [
                'document_id' => $document->id,
                'document_name' => $document->name,
                'document_description' => substr($document->description ?? '', 0, 100),
                'classic_tags_by_type' => $document->classic_tags_by_type ?? [],
                'semantic_tags' => $document->semantic_tags ?? [],
                'questions' => $document->questions ?? [],
            ]);

            $process->setComplete([
                'phase' => 'metadata_generated',
                'metadata_fields' => array_keys($appliedMetadata),
            ]);

            Log::channel('document-queue')->info('[GenerateMetadataJob] Dispatching DocumentMetadataGeneratedEvent', [
                'document_id' => $document->id,
                'process_id' => $process->id,
                'applied_metadata_keys' => array_keys($appliedMetadata),
            ]);

            DocumentMetadataGeneratedEvent::dispatch(
                $document,
                $process->id,
                $appliedMetadata,
                true,
                null,
            );
        } catch (ModelNotFoundException $exception) {
            $this->logger()->warning('Process not found: ' . $this->processId);
            $this->fail($exception);
        } catch (Throwable $exception) {
            if ($document) {
                DocumentMetadataGeneratedEvent::dispatch(
                    $document,
                    $this->processId,
                    [],
                    false,
                    $exception->getMessage(),
                );
            }

            $this->fail($exception);
        }
    }

    /**
     * @return array<int, string>
     */
    protected function loadAvailableTagTypes(Document $document): array
    {
        return TagType::query()
            ->where('project_id', $document->project_id)
            ->pluck('alias')
            ->filter(fn ($alias): bool => is_string($alias) && trim($alias) !== '')
            ->map(fn ($alias): string => trim((string) $alias))
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $availableTagTypes
     * @return array<string, array<int, string>>
     */
    protected function loadAvailableClassicTagsByType(Document $document, array $availableTagTypes = []): array
    {
        $tagTypeModel = \config('tags.tag_type_model', \SimoneBianco\LaravelSimpleTags\TagType::class);

        $query = $tagTypeModel::query()
            ->where('project_id', $document->project_id)
            ->with(['tags' => fn ($query) => $query->select('id', 'tag_type_id', 'name', 'slug')]);

        if (! empty($availableTagTypes)) {
            $query->whereIn('alias', $availableTagTypes);
        }

        $tagsByType = $query->get(['id', 'alias', 'label'])
            ->mapWithKeys(function ($tagType): array {
                $typeAlias = trim((string) ($tagType->alias ?? ''));

                if ($typeAlias === '') {
                    $typeAlias = Str::slug((string) ($tagType->label ?? ''));
                }

                if ($typeAlias === '') {
                    return [];
                }

                if ($tagType->tags->isEmpty()) {
                    return [$typeAlias => []];
                }

                $tagSlugs = $tagType->tags
                    ->map(function ($tag): ?string {
                        $slug = trim((string) ($tag->slug ?? ''));
                        if ($slug !== '') {
                            return $slug;
                        }

                        $name = trim((string) ($tag->name ?? ''));

                        return $name !== '' ? Str::slug($name) : null;
                    })
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                return [$typeAlias => $tagSlugs];
            })
            ->toArray();

        if (empty($availableTagTypes)) {
            return $tagsByType;
        }

        $tagsByType = array_replace(
            array_fill_keys($availableTagTypes, []),
            $tagsByType,
        );

        $normalized = [];

        foreach ($tagsByType as $type => $tags) {
            $normalizedType = trim((string) $type);
            if ($normalizedType === '') {
                continue;
            }

            $normalizedTags = [];
            foreach (is_array($tags) ? $tags : [] as $tag) {
                $normalizedTag = trim((string) $tag);
                if ($normalizedTag === '') {
                    continue;
                }

                $normalizedTags[] = $normalizedTag;
            }

            $normalized[$normalizedType] = array_values(array_unique($normalizedTags));
        }

        return $normalized;
    }

    protected function flushMetadataCachesBeforeBroadcast(Document $document): void
    {
        $cacheServiceClass = '\\App\\Services\\CacheInvalidationService';

        if (! class_exists($cacheServiceClass)) {
            return;
        }

        try {
            $cacheService = app($cacheServiceClass);

            if (method_exists($cacheService, 'flushMetadataForDocument')) {
                $cacheService->flushMetadataForDocument($document);
            }
        } catch (Throwable $exception) {
            Log::channel('document-queue')->warning('[GenerateMetadataJob] Failed to flush metadata caches before broadcast', [
                'document_id' => $document->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
