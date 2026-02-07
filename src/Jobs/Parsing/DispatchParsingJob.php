<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use SimoneBianco\LaravelRagChunks\Enums\ParsingPhase;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class DispatchParsingJob extends BaseDocumentParsingJob
{
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    protected function getJobName(): string
    {
        return 'dispatch_document_parsing';
    }

    public function __construct(string $documentId)
    {
        $this->documentId = $documentId;
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $process = null;
        try {
            $this->logger()->debug('Dispatch parsing job started');

            $document = Document::findOrFail($this->documentId);
            $process = $document->startProcess('document_parsing');

            Context::add('process_id', $process->id);

            $this->enrichContext([
                'process_id' => $process->id,
            ]);

            $this->logger()->debug('Process created');

            $process->mergeContextAndSave([
                'phase' => ParsingPhase::DISPATCHING->value
            ]);

            /** @var Document $document */
            $document = $process->processable;
            /** @var PdfParser $parser */
            $parser = DocumentParserFactory::make($document->extension);
            $dispatchData = $parser->dispatchParsing($document->getAbsolutePath());
            $process->setProcessing([...$dispatchData, ...['phase' => ParsingPhase::DISPATCHED->value]]);

            $this->logger()->debug('Dispatch parsing job finished');

            if ($parser->needsPolling()) {
                PollParsingJob::dispatch($process->id)->delay(60);
                $this->logger()->debug('Polling parsing job started');
            } else {
                RefineParsingResultsJob::dispatch($document->id, $process->id);
                $this->logger()->debug('Refining parsing job started');
            }
        } catch (ModelNotFoundException $exception) {
            $this->logger()->warning("Document not found: " . $this->documentId);
            $this->fail($exception);
        } catch (ClientException $e) {
            if ($e->isRetryable()) {
                $this->handleTemporaryFailure($e, $process, ['response' => $e->getResponse()]);
            }

            $this->fail($e);
        } catch (Throwable $e) {
            $this->logger()->error("Unexpected error in dispatch job", [
                'document_id' => $this->documentId,
                'process_id' => $this->processId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->fail($e);
        }
    }
}
