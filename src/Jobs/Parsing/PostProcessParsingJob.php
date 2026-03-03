<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Enums\Process\ParsingPhase;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Exceptions\PostProcessingException;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class PostProcessParsingJob extends BaseDocumentParsingJob implements ShouldBeUniqueUntilProcessing
{
    public int $tries = 12;
    public int $timeout = 7200;

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
        return $this->processId;
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $process = null;
        try {
            $this->enrichContext();

            $this->logger()->debug('Post processing parsing job started');

            $process = Process::with('processable')->findOrFail($this->processId);

            /** @var Document $document */
            $document = $process->processable;
            $this->documentId = $document->id;
            $this->enrichContext();

            $process->mergeContextAndSave([
                'phase' => ParsingPhase::POST_PROCESSING->value
            ]);

            if ($process->context['phase'] !== ParsingPhase::POST_PROCESSING) {
                $process->mergeContextAndSave(['phase' => ParsingPhase::POST_PROCESSING->value]);
            }

            /** @var PdfParser $parser */
            $parser = DocumentParserFactory::make($document->extension);

            $postprocessorOptions = $process->context['postprocessor'] ?? [];

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

            try {
                $postProcessedContext = $parser->postProcess(
                    $document->description,
                    $parser->contextFromArray($process->context),
                    config('rag_chunks.agents.postprocessor.batch_size', 10),
                    $postprocessorOptions,
                    $resumeFromLine,
                    function (int $lastInputLine) use ($process): void {
                        $process->mergeContextAndSave(['postprocessor_last_input_line' => $lastInputLine]);
                    }
                );
            } catch (PostProcessingException $exception) {
                if ($exception->isRetryable()) {
                    throw $exception; // outer catch handles retry via handleTemporaryFailure
                }
                // Errore strutturale non recuperabile: segna il processo come non riprovabile
                $process->setError($exception->getMessage(), [
                    ParsingPhase::POST_PROCESSING->value => $exception->toArray(),
                    'is_retryable' => false,
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
        }
    }
}
