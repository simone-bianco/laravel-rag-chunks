<?php

namespace SimoneBianco\LaravelRagChunks\Jobs;

use Illuminate\Contracts\Broadcasting\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Exceptions\ExtensionParsingNotSupportedException;
use Throwable;

class PollDocumentParsingJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function uniqueId(): string
    {
        return $this->documentId;
    }

    public function __construct(
        protected string $documentId,
        protected string $processId,
        protected LoggerInterface $logger
    ) {
        $this->logger = Log::channel('document-queue');
    }

    public function handle(): void
    {
        try {
            $process = Process::with('document')->findOrFail($this->processId);


        } catch (ModelNotFoundException $exception) {
            $this->logger->warning("Document '$this->documentId' with process ID '$this->processId' not found");
        } catch (ExtensionParsingNotSupportedException $e) { // @phpstan-ignore-line
             $this->logErrorToProcess($e->getMessage());
        } catch (Throwable $e) {
             $this->logErrorToProcess($e->getMessage());
        }
    }

    protected function logErrorToProcess(string $message): void
    {
        try {
             $process = Process::find($this->processId);
             $process?->setError($message);
        } catch (Throwable) {
             $this->logger->error("Failed to update process status for {$this->processId}: $message");
        }
    }
}
