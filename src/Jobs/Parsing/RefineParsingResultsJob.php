<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Enums\ParsingPhase;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class RefineParsingResultsJob extends BaseDocumentParsingJob
{
    public int $tries = 1;

    public function backoff(): array
    {
        return [];
    }

    protected function getJobName(): string
    {
        return 'document_refining';
    }

    public function __construct(string $processId)
    {
        $this->processId = $processId;
    }

    public function uniqueId(): string
    {
        return $this->processId;
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $this->enrichContext();

        $this->logger()->debug('Refining parsing job started');

        $process = Process::with('processable')->findOrFail($this->processId);

        /** @var \SimoneBianco\LaravelRagChunks\Models\Document $document */
        $document = $process->processable;
        $this->documentId = $document->id;
        $this->enrichContext();

        /** @var PdfParser $parser */
        $parser = DocumentParserFactory::make($document->extension);

        $refinedContext = $parser->refineOutputJson($parser->contextFromArray($process->context));
        $process->mergeContextAndSave([...$refinedContext->toArray(), ...['phase' => ParsingPhase::REFINED->value]]);

        PostProcessParsingJob::dispatch($process->id);

        $this->logger()->debug('Refining parsing job finished');
    }
}
