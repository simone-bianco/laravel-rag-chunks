<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class DispatchDocumentParsingJob extends BaseDocumentParsingJob
{
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    protected function getJobName(): string
    {
        return 'dispatch_document_parsing';
    }

    public function __construct(Document $document)
    {
        $this->documentId = $document->id;
        $this->processId = $document->startProcess('document_parsing')->id;
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

            /** @var PdfParser $parser */
            $parser = DocumentParserFactory::make($process->document->extension);
            $dispatchData = $parser->dispatchParsing($process->document->getAbsolutePath());
            $process->setProcessing($dispatchData);

            if ($parser->needsPolling()) {
                PollDocumentParsingJob::dispatch($process->document->id, $process->id)->delay(60);
            } else {
                ChunkParsingResultsJob::dispatch($process->document->id, $process->id);
            }
        } catch (ModelNotFoundException $exception) {
            $this->logger()->warning("Process not found: " . $this->documentId);
            $this->fail($exception);
        } catch (ClientException $e) {
            if ($e->isRetryable()) {
                $this->handleTemporaryFailure($e, $process, ['response' => $e->getResponse()]);
            }

            $this->fail($e);
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }
}
