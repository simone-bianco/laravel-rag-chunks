<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\KnowledgeGraph;

use App\Events\KnowledgeGraphComputedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SimoneBianco\LaravelRagChunks\Services\KnowledgeGraph\GraphCacheService;
use SimoneBianco\LaravelRagChunks\Services\KnowledgeGraph\KnowledgeGraphService;
use Throwable;

class ComputeKnowledgeGraphJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const LOG_CHANNEL = 'knowledge-graph';

    public int $timeout = 300;
    public int $tries = 2;
    public int $backoff = 30;

    public function __construct(
        public readonly string $projectId,
        public readonly bool   $force = false,
    ) {}

    public function handle(KnowledgeGraphService $service): void
    {
        $log = Log::channel(self::LOG_CHANNEL);

        if (! $this->force) {
            $cache = app(GraphCacheService::class);
            $cached = $cache->get($this->projectId);
            $currentVersion = $cache->computeVersion($this->projectId);

            $log->debug('job.cache_check', [
                'project_id'      => $this->projectId,
                'has_cache'       => $cached !== null,
                'cached_version'  => $cached?->version,
                'current_version' => $currentVersion,
            ]);

            if ($cached !== null && $cached->version === $currentVersion) {
                $log->info('job.skipped_fresh_cache', ['project_id' => $this->projectId]);
                return;
            }
        }

        $log->info('job.started', [
            'project_id' => $this->projectId,
            'force'      => $this->force,
            'attempt'    => $this->attempts(),
        ]);

        $t = microtime(true);

        $dto = $service->computeAndCache($this->projectId);

        $elapsed = round((microtime(true) - $t) * 1000);

        $log->info('job.completed', [
            'project_id'    => $this->projectId,
            'elapsed_ms'    => $elapsed,
            'nodes'         => count($dto->chunkNodes),
            'edges'         => count($dto->edges),
            'clusters'      => count($dto->clusters),
            'version'       => $dto->version,
        ]);

        KnowledgeGraphComputedEvent::dispatch($this->projectId);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel(self::LOG_CHANNEL)->error('job.failed', [
            'project_id' => $this->projectId,
            'attempt'    => $this->attempts(),
            'exception'  => $exception->getMessage(),
        ]);
    }
}
