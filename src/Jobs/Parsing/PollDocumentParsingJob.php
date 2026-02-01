<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts\DocumentParserInterface;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class PollDocumentParsingJob extends BaseDocumentParsingJob
{
    public int $tries = 12;

    public function backoff(): array
    {
        return [5, 10, 15, 30, 60, 120, 180, 300, 600, 900, 1800, 3600];
    }

    protected function getJobName(): string
    {
        return 'poll_document_parsing';
    }

    public function __construct(string $documentId, string $processId)
    {
        $this->documentId = $documentId;
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

            $process = Process::with('document')->findOrFail($this->processId);

            Context::push('process_id', $process->id);

            /** @var PdfParser $parser */
            $parser = DocumentParserFactory::make($process->document->extension);
            $status = $parser->pollParsing($process->context);

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

    protected function handleCompleted(Process $process, DocumentParserInterface $parser): void
    {
        $context = $parser->saveParsingResult($process->data);
        $process->mergeContextAndSave($context);
        $this->logger()->info("Polling completed for document {$this->documentId}");
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
