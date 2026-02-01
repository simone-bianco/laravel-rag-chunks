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

        $process = Process::with('document')->findOrFail($this->processId);

        /** @var PdfParser $parser */
        $parser = DocumentParserFactory::make($process->document->extension);

        $chunkingData = $parser->refineOutputJson($process->context);
        $process->mergeContextAndSave([$chunkingData, ...array_filter([
            'phase' => ParsingPhase::REFINED->value,
        ])]);
    }
}
