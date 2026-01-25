<?php

namespace SimoneBianco\LaravelRagChunks\Jobs;

use Illuminate\Contracts\Broadcasting\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class PollDocumentParsingJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 10;
    protected string $documentId;
    protected string $processId;

    public function backoff(): array
    {
        return [5, 10, 15, 30, 60, 120, 180, 300, 600, 900];
    }

    public function uniqueId(): string
    {
        return $this->documentId;
    }

    protected function logger(): LoggerInterface
    {
        return Log::channel('document-queue');
    }

    public function __construct(string $documentId, string $processId)
    {
        $this->documentId = $documentId;
        $this->processId = $processId;
    }

    protected function enrichContext(): void
    {
        Context::add([
            'laravel_job' => 'poll_document_parsing',
            'document_id' => $this->documentId,
            'process_id' => $this->processId,
            'trial' => $this->attempts(),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $process = null;
        try {
            $this->enrichContext();

            /** @var Process $process */
            $process = Process::with('document')->findOrFail($this->processId);

            Context::push('process_id', $process->id);

            /** @var PdfParser $parser */
            $parser = DocumentParserFactory::make($process->document->extension);
            $status = $parser->pollParsing($process->data);

            match ($status) {
                ParserStatus::COMPLETED => $this->handleCompleted($process),
                ParserStatus::PROCESSING => $this->handleProcessing($process),
                ParserStatus::FAILED => $this->handleFailed($process, "Parser returned FAILED status"),
            };
        } catch (ModelNotFoundException $exception) {
            $this->logger()->warning("Process not found: " . $this->processId);
            $this->fail($exception);
        } catch (ClientException $e) {
            $this->handleTemporaryFailure($e, $process, ['response' => $e->getResponse()]);
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    protected function handleCompleted(Process $process): void
    {
        $process->setProcessing(['message' => 'Parsing completed, ready for result retrieval']);
        $this->logger()->info("Polling completed for document {$this->documentId}");
    }

    protected function handleProcessing(Process $process): void
    {
        $currentTrial = $this->attempts();
        $totalTries = $this->tries;

        $process->setProcessing(['message' => "Still processing... (poll attempt $currentTrial/$totalTries)"]);
        $this->logger()->info("Document {$this->documentId} still processing, will retry... ($currentTrial/$totalTries)");

        throw new ClientException(
            message: "Document still processing, retrying...",
            response: ['status' => 'processing', 'attempt' => $currentTrial],
        );
    }

    protected function handleFailed(Process $process, string $message): void
    {
        $process->setError($message);
        $this->logger()->error("Parsing failed for document {$this->documentId}: $message");
        $this->fail(new ClientException(message: $message));
    }

    /**
     * @throws Throwable
     */
    protected function handleTemporaryFailure(Throwable $exception, ?Process $process, array $context = []): void
    {
        $currentTrial = $this->attempts();
        $totalTries = $this->tries;

        $context = array_merge($context, [
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        if ($process) {
            $this->logger()->warning("Temporary failure for poll document, retry... ($currentTrial/$totalTries)", $context);
        }

        throw $exception;
    }

    public function failed(Throwable $exception): void
    {
        try {
            $this->enrichContext();

            $process = Process::find($this->processId);

            if ($process) {
                $process->setError("FINAL FAILURE. Polling job aborted.", [
                    'message' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString(),
                    'final_attempt' => $this->attempts()
                ]);
            } else {
                $this->logger()->error("[Final Failure] Process not found for document {$this->documentId}");
            }
        } catch (Throwable $e) {
            $this->logger()->critical("CRITICAL: Failed to log job failure. Original: {$exception->getMessage()}. New: {$e->getMessage()}");
        }
    }
}
