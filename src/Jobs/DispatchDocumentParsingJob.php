<?php

namespace SimoneBianco\LaravelRagChunks\Jobs;

use Illuminate\Contracts\Broadcasting\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use SimoneBianco\DolphinParser\Exceptions\ApiRequestException;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Exceptions\ParsingDispatchException;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class DispatchDocumentParsingJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    protected string $documentId;
    protected string $processId;

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function uniqueId(): string
    {
        return $this->documentId;
    }

    protected function logger(): LoggerInterface
    {
        return Log::channel('document-queue');
    }

    public function __construct(Document $document)
    {
        $this->documentId = $document->id;
        $this->processId = $document->startProcess()->id;
    }

    protected function enrichContext(): void
    {
        Context::add([
            'laravel_job' => 'dispatch_document_parsing',
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
            $parser->dispatchParsing($process->document);
            $process->setProcessing(['message' => "Document parsing dispatched"]);
        } catch (ModelNotFoundException $exception) {
            $this->logger()->warning("Process not found: " . $this->documentId);
            $this->fail($exception);
        } catch (ConnectionException $e) {
            $this->handleTemporaryFailure($e, $process);
        } catch (ApiRequestException|ParsingDispatchException $e) {
            $this->handleTemporaryFailure($e, $process, ['response' => $e->getResponse()]);
        } catch (Throwable $e) {
            $this->fail($e);
        }
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
            $this->logger()->warning("Temporary failure for dispatch document, retry... ($currentTrial/$totalTries)", $context);
        }

        throw $exception;
    }

    public function failed(Throwable $exception): void
    {
        try {
            $this->enrichContext();

            $process = Process::find($this->processId);

            if ($process) {
                $process->setError("[DispatchDocumentParsingJob] FINAL FAILURE. Job aborted.", [
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
