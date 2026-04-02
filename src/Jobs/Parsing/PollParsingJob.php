<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Enums\Process\ParsingPhase;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts\DocumentParserInterface;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class PollParsingJob extends BaseDocumentParsingJob
{
    public int $tries = 19;

    public function backoff(): array
    {
        return [5, 10, 15, 30, 60, 60, 60, 60, 60, 60, 60, 60, 120, 180, 360, 720, 1440, 2880, 3600];
    }

    protected function getJobName(): string
    {
        return 'poll_document_parsing';
    }

    public function uniqueId(): string
    {
        return $this->processId;
    }

    public function __construct(string $processId)
    {
        $this->processId = $processId;
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $process = null;
        try {
            $this->enrichContext();

            $this->logger()->debug('Polling parsing job started');

            $process = Process::with('processable')->findOrFail($this->processId);

            $phase = $process->context['phase'] ?? 'unknown';
            if ($this->handleStopSignal($process, $phase)) return;

            /** @var \SimoneBianco\LaravelRagChunks\Models\Document $document */
            $document = $process->processable;
            $this->documentId = $document->id;

            $this->enrichContext();

            if ($process->context['phase'] !== ParsingPhase::POLLING->value) {
                $process->mergeContextAndSave([
                    'phase' => ParsingPhase::POLLING->value
                ]);
            }

            /** @var PdfParser $parser */
            $parser = DocumentParserFactory::make($this->resolveParserExtension($process, (string) $document->extension));
            $status = $parser->pollParsing($parser->contextFromArray($process->context));

            match ($status) {
                ParserStatus::COMPLETED => $this->handleCompleted($process, $parser),
                ParserStatus::PROCESSING => $this->handleProcessing(),
                ParserStatus::FAILED => $this->handleFailed($process, 'Parser returned FAILED status'),
            };
        } catch (ModelNotFoundException $exception) {
            $this->logger()->warning('Process not found: '.$this->processId);
            $this->fail($exception);
        } catch (ClientException $e) {
            if ($e->isRetryable()) {
                $this->handleTemporaryFailure($e, $process, ['response' => $e->getResponse()]);
            }

            $this->handleFailed($process, $e->getMessage());
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    /**
     * @param Process $process
     * @param DocumentParserInterface $parser
     * @return void
     * @throws ClientException
     */
    protected function handleCompleted(Process $process, DocumentParserInterface $parser): void
    {
        $updatedContext = $parser->saveParsingResult($parser->contextFromArray($process->context));
        $process->mergeContextAndSave([...$updatedContext->toArray(), ...['phase' => ParsingPhase::REFINING->value]]);
        $this->logger()->info("Polling completed for document {$this->documentId}");

        RefineParsingResultsJob::dispatch($process->id);
    }

    protected function handleProcessing(): void
    {
        $currentTrial = $this->attempts();
        $totalTries = $this->tries;

        $this->logger()->info("Document {$this->documentId} still processing, will retry... ($currentTrial/$totalTries)");

        $backoff = $this->backoff();
        $delay = $backoff[$currentTrial - 1] ?? end($backoff);

        $this->release($delay);
    }

    protected function handleFailed(Process $process, string $message): void
    {
        $process->setError($message);
        $this->logger()->error("Parsing failed for document {$this->documentId}: $message");
        $this->fail(new ClientException(message: $message));
    }
}
