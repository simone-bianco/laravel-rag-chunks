<?php

namespace SimoneBianco\LaravelRagChunks\Jobs;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class ChunkDocumentJob extends BaseDocumentParsingJob
{
    public function backoff(): array
    {
        return [];
    }

    public int $tries = 0;

    protected function getJobName(): string
    {
        return 'chunk_document';
    }

    public function __construct(Document $document, Process $process)
    {
        $this->documentId = $document->id;
        $this->processId = $process->id;
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
            $dispatchData = $parser->dispatchParsing($process->document->getAbsolutePath());
            $process->setProcessing($dispatchData);
        } catch (ModelNotFoundException $exception) {
            $this->logger()->warning("Process not found: " . $this->documentId);
            $this->fail($exception);
        } catch (ClientException $e) {
            $this->handleTemporaryFailure($e, $process, ['response' => $e->getResponse()]);
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }
}
