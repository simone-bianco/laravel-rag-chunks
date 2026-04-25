<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use App\Events\PostProcessingProgressEvent;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Enums\Process\ParsingPhase;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Exceptions\PostProcessingException;
use SimoneBianco\LaravelRagChunks\Exceptions\ProcessStoppedException;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class PostProcessParsingJob extends BaseDocumentParsingJob implements ShouldBeUnique
{
    public int $tries = 12;
    public int $timeout = 7200;
    public int $uniqueFor = 14400;

    public function backoff(): array
    {
        return [5, 10, 15, 30, 60, 120, 180, 300, 600, 900, 1800, 3600];
    }

    protected function getJobName(): string
    {
        return 'document_post_processing';
    }

    public function __construct(string $processId)
    {
        $this->processId = $processId;
    }

    public function uniqueId(): string
    {
        $documentId = Process::query()
            ->whereKey($this->processId)
            ->where('processable_type', Document::class)
            ->value('processable_id');

        return $documentId !== null
            ? 'document:' . (string) $documentId
            : 'process:' . $this->processId;
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $process = null;
        $lock = null;

        try {
            $this->enrichContext();

            $this->logger()->debug('Post processing parsing job started');

            $process = Process::with('processable')->findOrFail($this->processId);

            /** @var Document $document */
            $document = $process->processable;
            $this->documentId = $document->id;
            $this->enrichContext();

            $lockKey = "rag_chunks:post_process:document:{$document->id}";
            $lockTtl = $this->timeout + 300;
            $lock = Cache::lock($lockKey, $lockTtl);

            if (!$lock->get()) {
                $this->logger()->warning('Post processing already running for this document, skipping duplicate execution', [
                    'process_id' => (string) $this->processId,
                    'document_id' => (string) $document->id,
                    'lock_key' => $lockKey,
                    'lock_ttl_seconds' => $lockTtl,
                ]);

                return;
            }

            $process->setProcessing([
                'phase' => ParsingPhase::POST_PROCESSING->value
            ]);

            if ($this->handleStopSignal($process, ParsingPhase::POST_PROCESSING->value)) return;

            /** @var PdfParser $parser */
            $parser = DocumentParserFactory::make($this->resolveParserExtension($process, (string) $document->extension));

            $postprocessorOptions = $process->context['postprocessor'] ?? [];

            if (!empty($postprocessorOptions['parse_by_image'])) {
                $postprocessorOptions['parse_by_image_dir'] ??= 'parse-by-image-process-' . $process->id;
            }
            $postprocessorOptions['source_pdf_path'] = (string) ($process->context['source_file_path'] ?? '');

            $this->logger()->info('Post-processing mode selected', [
                'document_id' => (string) $document->id,
                'process_id' => (string) $process->id,
                'parse_by_image' => (bool) ($postprocessorOptions['parse_by_image'] ?? false),
                'source_pdf_path' => $postprocessorOptions['source_pdf_path'],
            ]);

            // Inject project-level post-processor instructions
            $projectInstructions = $document->project?->settings?->post_processor_agent_instructions;
            if (!empty($projectInstructions)) {
                $existing = $postprocessorOptions['extra_instructions'] ?? '';
                $postprocessorOptions['extra_instructions'] = $existing
                    ? trim($existing) . "\n\n" . $projectInstructions
                    : $projectInstructions;
            }

            if (!empty($postprocessorOptions['assign_tags'])) {
                $tagTypeModel = config('tags.tag_type_model', \SimoneBianco\LaravelSimpleTags\TagType::class);
                $tagsByType = $tagTypeModel::where('project_id', $document->project_id)
                    ->where('ai_assign', true)
                    ->with(['tags' => fn ($q) => $q->select('id', 'tag_type_id', 'name', 'slug')])
                    ->get()
                    ->filter(fn ($type) => $type->tags->isNotEmpty())
                    ->mapWithKeys(fn ($type) => [$type->alias => $type->tags->pluck('slug')->toArray()])
                    ->toArray();

                if (!empty($tagsByType)) {
                    $postprocessorOptions['tags_by_type'] = $tagsByType;
                }
            }

            // Riprendi dall'ultima riga input processata con successo (0 = partenza fresca)
            $resumeFromLine = (int) ($process->context['postprocessor_last_input_line'] ?? 0);

            $this->logger()->info('Post-processing execution context', [
                'process_id' => (string) $process->id,
                'document_id' => (string) $document->id,
                'parse_by_image' => (bool) ($postprocessorOptions['parse_by_image'] ?? false),
                'batch_size' => (int) config('rag_chunks.agents.postprocessor.batch_size', 8),
                'resume_from_line' => $resumeFromLine,
            ]);

            try {
                $postProcessedContext = $parser->postProcess(
                    $document->description,
                    $parser->contextFromArray($process->context),
                    config('rag_chunks.agents.postprocessor.batch_size', 8),
                    $postprocessorOptions,
                    $resumeFromLine,
                    function (int $lastInputLine) use ($process, $document): void {
                        $process->mergeContextAndSave(['postprocessor_last_input_line' => $lastInputLine]);

                        PostProcessingProgressEvent::dispatch(
                            $document->id,
                            $process->id,
                            $lastInputLine,
                        );

                        if ($process->isStopSignaled()) {
                            throw new ProcessStoppedException('Stop signal received during post-processing.');
                        }
                    }
                );
            } catch (ProcessStoppedException) {
                $this->handleStopSignal($process, ParsingPhase::POST_PROCESSING->value);
                return;
            } catch (PostProcessingException $exception) {
                if ($exception->isRetryable()) {
                    throw $exception; // outer catch handles retry via handleTemporaryFailure
                }

                $manualRetryable = str_contains($exception->getMessage(), 'Unexpected finish reason: content_filter')
                    || str_contains($exception->getMessage(), 'finished with reason: SAFETY')
                    || str_contains($exception->getMessage(), 'finished with reason: RECITATION');

                // Errore strutturale non recuperabile: segna il processo come non riprovabile
                $process->setError($exception->getMessage(), [
                    ParsingPhase::POST_PROCESSING->value => $exception->toArray(),
                    'is_retryable' => $manualRetryable,
                ]);

                $this->fail($exception);
                return;
            }

            $process->mergeContextAndSave([...$postProcessedContext->toArray(), ...['phase' => ParsingPhase::POST_PROCESSED->value]]);

            SaveParsingJob::dispatch($process->id);

            $this->logger()->debug('Post processing parsing job finished');
        } catch (ModelNotFoundException $exception) {
            $this->logger()->warning('Process not found: '.$this->processId);
            $this->fail($exception);
        } catch (PostProcessingException $e) {
            if ($e->isRetryable()) {
                $this->handleTemporaryFailure($e, $process);
            }

            $this->fail($e);
        } catch (Throwable $e) {
            $this->logger()->error("Unexpected exception in {$this->getJobName()}", [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->fail($e);
        } finally {
            if ($lock !== null) {
                optional($lock)->release();
            }
        }
    }
}
