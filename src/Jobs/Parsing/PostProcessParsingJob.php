<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Enums\Process\ParsingPhase;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Exceptions\PostProcessingException;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class PostProcessParsingJob extends BaseDocumentParsingJob
{
    public int $tries = 12;

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

            try {
                $postProcessedContext = $parser->postProcess($document->description, $parser->contextFromArray($process->context));
            } catch (PostProcessingException $exception) {
                if ($exception->isRetryable()) {
                    throw $exception; // outer catch handles retry via handleTemporaryFailure
                }
                $process->setError($exception->getMessage(), [
                    ParsingPhase::POST_PROCESSING->value => $exception->toArray()
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
