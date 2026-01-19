<?php

namespace SimoneBianco\LaravelRagChunks\Jobs;

use Illuminate\Contracts\Broadcasting\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
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
        return $this->documentId;
    }

    public function __construct(protected string $documentId, protected LoggerInterface $logger)
    {
        $this->logger = Log::channel('document-queue');
    }

    public function handle(): void
    {
        try {
            /** @var Document $document */
            $document = Document::findOrFail($this->documentId);
            $parser = DocumentParserFactory::make($document->extension);

            DB::transaction(function () use ($document, $parser) {
                $document->setProcessing("Document parsing dispatched with " . get_class($parser));
                $parser->dispatchParsing($document);
            });
        } catch (ModelNotFoundException $exception) {
            $this->logger->warning("Document not found: " . $this->documentId);
        } catch (ExtensionParsingNotSupportedException $e) {
            $document->setError($e->getMessage());
        } catch (Throwable $e) {
            $document->setError($e->getMessage());
        }
    }
}
