<?php

namespace SimoneBianco\LaravelRagChunks\Jobs;

use Illuminate\Contracts\Broadcasting\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDocumentJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function __construct(protected string $documentId) {}

    public function handle(): void
    {
        logger()->debug("Processed document $this->documentId");
    }
}
