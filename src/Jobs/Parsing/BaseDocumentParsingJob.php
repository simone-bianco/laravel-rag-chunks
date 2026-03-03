<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Enums\Process\ParsingPhase;
use SimoneBianco\LaravelRagChunks\Jobs\BaseProcessJob;

abstract class BaseDocumentParsingJob extends BaseProcessJob
{
    protected ?string $documentId = null;

    protected ?string $processId = null;

    abstract public function backoff(): array;

    public function uniqueId(): string
    {
        return $this->documentId;
    }

    protected function logger(): LoggerInterface
    {
        return Log::channel('document-queue');
    }

    protected function enrichContext(array $extra = []): void
    {
        Context::add([
            'laravel_job' => $this->getJobName(),
            'document_id' => $this->documentId ?? null,
            'process_id' => $this->processId ?? null,
            'trial' => $this->attempts(),
            ...$extra,
        ]);
    }

    /**
     * Check if a stop signal has been sent for this process.
     * If so, marks the process as manually stopped and returns true.
     *
     * @return bool True if stop signal was found (caller should return immediately).
     */
    protected function handleStopSignal(Process $process, string $phase): bool
    {
        if (!$process->isStopSignaled()) {
            return false;
        }

        $isPostProcessing = in_array($phase, [
            ParsingPhase::POST_PROCESSING->value,
            ParsingPhase::POST_PROCESSED->value,
        ]);

        $process->clearStopSignal();
        $process->setError('Process stopped manually.', [
            'manually_stopped' => true,
            'stopped_at_phase' => $phase,
            'is_retryable'     => $isPostProcessing,
        ]);

        return true;
    }
}
