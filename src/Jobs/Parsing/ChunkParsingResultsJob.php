<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class ChunkParsingResultsJob extends BaseDocumentParsingJob
{
    public int $tries = 12;

    public function backoff(): array
    {
        return [];
    }

    protected function getJobName(): string
    {
        return 'document_chunking';
    }

    public function __construct(string $documentId, string $processId)
    {
        $this->documentId = $documentId;
        $this->processId = $processId;
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

            $refinedOutputJsonPath = $parser->refineOutputJson($process->data[self::ZIP_RELATIVE_PATH]);
            $process->setProcessing($dispatchData);
        } catch (ModelNotFoundException $exception) {
            $this->logger()->warning("Process not found: " . $this->processId);
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
