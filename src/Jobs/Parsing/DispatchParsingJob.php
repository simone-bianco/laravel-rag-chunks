<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use SimoneBianco\LaravelRagChunks\Enums\Process\ParsingPhase;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Services\Parsers\DocumentParserFactory;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use SimoneBianco\LaravelProcesses\Models\Process;
use Throwable;

class DispatchParsingJob extends BaseDocumentParsingJob
{
    public int $tries = 4;

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    protected function getJobName(): string
    {
        return 'dispatch_document_parsing';
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
        $process = null;
        try {
            $this->logger()->debug('Dispatch parsing job started');

            /** @var Process $process */
            $process = Process::findOrFail($this->processId);

            $phase = $process->context['phase'] ?? 'unknown';
            if ($this->handleStopSignal($process, $phase)) return;

            $this->enrichContext();

            $process->setProcessing([
                'phase' => ParsingPhase::DISPATCHING->value
            ]);

            $document = $process->processable;
            $this->documentId = $document->id;
            /** @var PdfParser $parser */
            $parser = DocumentParserFactory::make($this->resolveParserExtension($process, (string) $document->extension));
            $dispatchContext = $parser->dispatchParsing(
                $this->resolveParserAbsolutePath($process, $document->getAbsolutePath(), (string) ($document->disk ?? 'local'))
            );
            $dispatchData = $dispatchContext->toArray();
            $process->mergeContextAndSave([...$dispatchData, ...['phase' => ParsingPhase::DISPATCHED->value]]);

            $this->logger()->debug('Dispatch parsing job finished');

            if ($parser->needsPolling()) {
                PollParsingJob::dispatch($process->id)->delay(60);
                $process->setProcessing([...$dispatchData, ...['phase' => ParsingPhase::POLLING->value]]);
                $this->logger()->debug('Polling parsing job started');
            } else {
                RefineParsingResultsJob::dispatch($process->id);
                $process->setProcessing([...$dispatchData, ...['phase' => ParsingPhase::REFINING->value]]);
                $this->logger()->debug('Refining parsing job started');
            }
        } catch (ClientException $e) {
            if (!$e->isRetryable()) {
                $this->fail($e);
            }

            $this->handleTemporaryFailure($e, $process, ['response' => $e->getResponse()]);
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }
}
