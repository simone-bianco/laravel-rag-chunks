<?php

namespace SimoneBianco\LaravelRagChunks\Jobs;

use Illuminate\Contracts\Broadcasting\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\Exceptions\ExtensionParsingNotSupportedException;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use Throwable;

class PollDocumentParsingJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function uniqueId(): string
    {
        return "{$this->documentId}_$this->processId";
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
            /** @var Document $document */
            $document = Document::with(['processes' => function (Builder $query) {
                $query->where('id', $this->processId);
            }])->firstOrFail();

            $process = $document->processes->first();

            $parser = DocumentParserFactory::make($document->extension);

            DB::transaction(function () use ($document, $parser, $process) {
                // Here we use the specific process instance
                $process->setProcessing("Document parsing dispatched with " . get_class($parser));
                $parser->dispatchParsing($document);
            });
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
        // Try to find process directly if document load failed or not available in scope
        try {
             $process = \SimoneBianco\LaravelProcesses\Models\Process::find($this->processId);
             $process?->setError($message);
        } catch (Throwable) {
             // If everything fails, just log to system log
             $this->logger->error("Failed to update process status for {$this->processId}: $message");
        }
    }
}
