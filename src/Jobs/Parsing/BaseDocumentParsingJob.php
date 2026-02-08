<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelProcesses\Models\Process;
use Throwable;

abstract class BaseDocumentParsingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    protected ?string $documentId = null;

    protected ?string $processId = null;

    abstract public function backoff(): array;

    abstract protected function getJobName(): string;

    public function uniqueId(): string
    {
        return $this->documentId;
    }

    protected function logger(): LoggerInterface
    {
        return Log::channel('document-queue');
    }

    protected function enrichContext(array $extra = []): void
    {
        Context::add([
            'laravel_job' => $this->getJobName(),
            'document_id' => $this->documentId ?? null,
            'process_id' => $this->processId ?? null,
            'trial' => $this->attempts(),
            ...$extra,
        ]);
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
            $this->logger()->warning("Temporary failure for {$this->getJobName()}, retry... ($currentTrial/$totalTries)", $context);
        }

        throw $exception;
    }

    public function failed(Throwable $exception): void
    {
        try {
            $this->enrichContext();

            // Always log the error first
            $this->logger()->error("[Job Failed] {$this->getJobName()}", [
                'document_id' => $this->documentId ?? 'unknown',
                'process_id' => $this->processId ?? 'unknown',
                'exception_class' => get_class($exception),
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
                'attempt' => $this->attempts(),
            ]);

            if (isset($this->processId)) {
                $process = Process::find($this->processId);

                if ($process) {
                    $process->setError('FINAL FAILURE. Job aborted.', [
                        'message' => $exception->getMessage(),
                        'trace' => $exception->getTraceAsString(),
                        'final_attempt' => $this->attempts(),
                    ]);
                } else {
                    $this->logger()->error('[Final Failure] Process not found for document '.($this->documentId ?? 'unknown'));
                }
            }
        } catch (Throwable $e) {
            $this->logger()->critical("CRITICAL: Failed to log job failure. Original: {$exception->getMessage()}. New: {$e->getMessage()}");
        }
    }
}
