<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Enums\ParsingPhase;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class RefineParsingResultsJob extends BaseDocumentParsingJob
{
    public int $tries = 0;

    public function backoff(): array
    {
        return [];
    }

    protected function getJobName(): string
    {
        return 'document_refining';
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
        $this->enrichContext();

        $this->logger()->debug('Refining parsing job started');

        $process = Process::with('processable')->findOrFail($this->processId);

        /** @var \SimoneBianco\LaravelRagChunks\Models\Document $document */
        $document = $process->processable;

        /** @var PdfParser $parser */
        $parser = DocumentParserFactory::make($document->extension);

        $chunkingData = $parser->refineOutputJson($process->context);
        $process->mergeContextAndSave([$chunkingData, ...array_filter([
            'phase' => ParsingPhase::REFINED->value,
        ])]);

        PostProcessParsingJob::dispatch($document->id, $process->id);

        $this->logger()->debug('Refining parsing job finished');
    }
}
