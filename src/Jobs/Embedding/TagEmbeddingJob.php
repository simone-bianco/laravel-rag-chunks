<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Embedding;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Jobs\BaseProcessJob;
use SimoneBianco\LaravelRagChunks\Models\Tag;
use SimoneBianco\LaravelRagChunks\Services\TagService;
use Throwable;

class TagEmbeddingJob extends BaseProcessJob
{
    public int $tries = 2;

    public function backoff(): array
    {
        return [5, 10];
    }

    protected function getJobName(): string
    {
        return 'tag_embedding';
    }

    public function uniqueId(): string
    {
        return $this->processId;
    }

    protected function logger(): LoggerInterface
    {
        return Log::channel('tag-embedding-queue');
    }

    protected function enrichContext(array $extra = []): void
    {
        Context::add([
            'laravel_job' => $this->getJobName(),
            'process_id' => $this->processId ?? null,
            'trial' => $this->attempts(),
            ...$extra,
        ]);
    }

    public function __construct(protected string $processId) {}

    /**
     * @throws Throwable
     */
    public function handle(TagService $tagService): void
    {
        try {
            $this->enrichContext();

            $this->logger()->debug('Tag embedding job started');

            $process = Process::with('processable')->findOrFail($this->processId);

            /** @var Tag $tag */
            $tag = $process->processable;

            $this->enrichContext(['tag_id' => $tag->id]);

            $process->setProcessing();

            $tagService->embedAndSave($tag);

            $process->setComplete();

            $this->logger()->debug('Tag embedding job finished');
        } catch (ModelNotFoundException $exception) {
            $this->logger()->warning('Process not found: '.$this->processId);
            $this->fail($exception);
        } catch (ClientException $e) {
            if (!$e->isRetryable()) {
                $this->fail($e);
            }

            $this->handleTemporaryFailure($e, $process ?? null, ['response' => $e->getResponse()]);
        } catch (Throwable $e) {
            $this->logger()->error("Unexpected exception in {$this->getJobName()}", [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->fail($e);
        }
    }
}
