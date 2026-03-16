<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Enums\Process\ParsingPhase;
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
        try {
            $this->enrichContext();

            $this->logger()->debug('Saving parsing job started');

            $process = Process::with('processable')->findOrFail($this->processId);

            $process->setProcessing([
                'phase' => ParsingPhase::SAVING->value
            ]);

            if ($this->handleStopSignal($process, ParsingPhase::SAVING->value)) return;

            /** @var \SimoneBianco\LaravelRagChunks\Models\Document $document */
            $document = $process->processable;
            $this->documentId = $document->id;
            $this->enrichContext();

            /** @var PdfParser $parser */
            $parser = DocumentParserFactory::make($document->extension);
            $parser->saveDocument(
                $document,
                $parser->contextFromArray($process->context),
                [
                    'keep_chunks_with_images' => (bool) ($process->context['keep_chunks_with_images'] ?? false),
                ]
            );

            $process->setComplete(['phase' => ParsingPhase::COMPLETED->value]);

            $this->logger()->debug('Saving parsing job finished');
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }
}
