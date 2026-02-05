<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Enums\ParsingPhase;
use SimoneBianco\LaravelRagChunks\Exceptions\PostProcessingException;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class PostProcessParsingJob extends BaseDocumentParsingJob
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

        $this->logger()->debug('Post processing parsing job started');

        $process = Process::with('processable')->findOrFail($this->processId);

        /** @var \SimoneBianco\LaravelRagChunks\Models\Document $document */
        $document = $process->processable;

        $process->mergeContextAndSave([
            'phase' => ParsingPhase::POST_PROCESSING->value
        ]);

        if ($process->context['phase'] !== ParsingPhase::POST_PROCESSING) {
            $process->mergeContextAndSave(['phase' => ParsingPhase::POST_PROCESSING->value]);
        }

        /** @var PdfParser $parser */
        $parser = DocumentParserFactory::make($document->extension);

        try {
            $postProcessData = $parser->postProcess($document->description, $process->context);
        } catch (PostProcessingException $exception) {
            $process->setError($exception->getMessage(), [
                ParsingPhase::POST_PROCESSING->value => $exception->toArray()
            ]);
            $this->fail($exception);
            return;
        }

        $process->mergeContextAndSave([$postProcessData, ...['phase' => ParsingPhase::POST_PROCESSED->value]]);

        SaveParsingJob::dispatch($document->id, $process->id);

        $this->logger()->debug('Post processing parsing job finished');
    }
}
