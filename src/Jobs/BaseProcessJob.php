<?php

namespace SimoneBianco\LaravelRagChunks\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelProcesses\Models\Process;
use Throwable;

abstract class BaseProcessJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    abstract protected function getJobName(): string;
    abstract protected function logger(): LoggerInterface;
    abstract protected function enrichContext(array $extra = []): void;

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

        $this->logger()->warning("Temporary failure for {$this->getJobName()}, retry... ($currentTrial/$totalTries)", $context);

        throw $exception;
    }

    public function failed(Throwable $exception): void
    {
        try {
            $this->enrichContext();

            $this->logger()->error("[Job Failed] {$this->getJobName()}", [
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
