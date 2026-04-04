<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\KnowledgeGraph;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SimoneBianco\LaravelRagChunks\Services\KnowledgeGraph\GraphCacheService;
use App\Events\KnowledgeGraphComputedEvent;
use SimoneBianco\LaravelRagChunks\Services\KnowledgeGraph\KnowledgeGraphService;
use Throwable;

class ComputeKnowledgeGraphJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 2;
    public int $backoff = 30;

    public function __construct(
        public readonly string $projectId,
        public readonly bool   $force = false,
    ) {}

    public function handle(KnowledgeGraphService $service): void
    {
        if (! $this->force) {
            $cache = app(GraphCacheService::class);
            $cached = $cache->get($this->projectId);

            if ($cached !== null && $cached->version === $cache->computeVersion($this->projectId)) {
                Log::debug('ComputeKnowledgeGraphJob: cache is fresh, skipping', [
                    'project_id' => $this->projectId,
                ]);

                return;
            }
        }

        Log::info('ComputeKnowledgeGraphJob: computing graph', [
            'project_id' => $this->projectId,
            'force' => $this->force,
        ]);

        $service->computeAndCache($this->projectId);

        KnowledgeGraphComputedEvent::dispatch($this->projectId);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ComputeKnowledgeGraphJob: failed', [
            'project_id' => $this->projectId,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
