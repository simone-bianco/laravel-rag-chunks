<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Enums\ParsingPhase;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class SaveParsingJob extends BaseDocumentParsingJob
{
    public int $tries = 12;

    public function backoff(): array
    {
        return [5, 10, 15, 30, 60, 120, 180, 300, 600, 900, 1800, 3600];
    }

    protected function getJobName(): string
    {
        return 'document_post_processing';
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

        $process->mergeContextAndSave([
            'phase' => ParsingPhase::POST_PROCESSING->value
        ]);

        if ($process->context['phase'] !== ParsingPhase::POST_PROCESSING) {
            $process->mergeContextAndSave(['phase' => ParsingPhase::POST_PROCESSING->value]);
        }

        /** @var PdfParser $parser */
        $parser = DocumentParserFactory::make($process->document->extension);

        $postProcessData = $parser->postProcess($process->document->description, $process->context);
        $process->mergeContextAndSave([$postProcessData, ...['phase' => ParsingPhase::POST_PROCESSED->value]]);
    }
}
