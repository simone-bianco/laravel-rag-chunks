<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Enums\ParsingPhase;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class SaveParsingJob extends BaseDocumentParsingJob
{
    public int $tries = 1;

    public function backoff(): array
    {
        return [];
    }

    protected function getJobName(): string
    {
        return 'document_saving';
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

        $this->logger()->debug('Saving parsing job started');

        $process = Process::with('processable')->findOrFail($this->processId);

        /** @var \SimoneBianco\LaravelRagChunks\Models\Document $document */
        $document = $process->processable;

        $process->mergeContextAndSave([
            'phase' => ParsingPhase::SAVING->value
        ]);

        /** @var PdfParser $parser */
        $parser = DocumentParserFactory::make($document->extension);
        $parser->saveDocument($document, $process->context);

        $process->setComplete(['phase' => ParsingPhase::COMPLETED->value]);

        $this->logger()->debug('Saving parsing job finished');
    }
}
