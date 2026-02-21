<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Parsing;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
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
}
