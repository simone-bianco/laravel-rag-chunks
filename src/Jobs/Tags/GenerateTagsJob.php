<?php

namespace SimoneBianco\LaravelRagChunks\Jobs\Tags;

use App\Models\TagType;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\AiAgents\TagsCreatorAgent;
use SimoneBianco\LaravelRagChunks\Jobs\BaseProcessJob;
use SimoneBianco\LaravelRagChunks\Models\Project;
use SimoneBianco\LaravelRagChunks\Models\Tag;
use Throwable;

class GenerateTagsJob extends BaseProcessJob
{
    public int $tries = 3;

    public function backoff(): array
    {
        return [5, 30, 60];
    }

    protected function getJobName(): string
    {
        return 'tags_generation';
    }

    public function uniqueId(): string
    {
        return $this->processId;
    }

    protected function logger(): LoggerInterface
    {
        return Log::channel('regenerate-tags');
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
    public function handle(): void
    {
        try {
            $this->enrichContext();

            $this->logger()->info('Tags generation job started', ['process_id' => $this->processId]);

            $process = Process::with('processable')->findOrFail($this->processId);

            /** @var Project $project */
            $project = $process->processable;

            $this->enrichContext(['project_id' => $project->id]);

            $this->logger()->info('Project loaded', [
                'project_id'    => $project->id,
                'project_alias' => $project->alias,
                'project_name'  => $project->name,
            ]);

            $process->setProcessing();
            $this->logger()->debug('Process status set to processing');

            $additionalInstructions = $process->getContext('additional_instructions');

            $agent = new TagsCreatorAgent(Str::random(), $project->alias);

            if ($additionalInstructions) {
                $agent->withAdditionalInstructions($additionalInstructions);
                $this->logger()->debug('Additional instructions applied', ['instructions' => $additionalInstructions]);
            }

            $this->logger()->info('Calling TagsCreatorAgent...');

            $response = $agent->respond(
                'Generate the optimal set of tags for this project based on its name and description. Be analytical and precise.'
            );

            $rawTagCount = count($response['tags'] ?? []);
            $this->logger()->info('Agent response received', [
                'raw_tag_count' => $rawTagCount,
                'raw_tags'      => $response['tags'] ?? [],
            ]);

            // Delete existing tags and tag types before saving new ones (regeneration)
            $tagTypeModel = config('tags.tag_type_model', TagType::class);
            $deleted = $tagTypeModel::where('project_id', $project->id)->delete();
            $this->logger()->info('Existing tag types deleted', ['deleted_count' => $deleted]);

            $generatedTags = [];
            $typesSeen = [];

            foreach ($response['tags'] as $tagItem) {
                $typeAlias = Str::slug($tagItem['type']);
                $typeLabel = ucfirst($tagItem['type']);

                $tagType = $tagTypeModel::firstOrCreate(
                    ['project_id' => $project->id, 'alias' => $typeAlias],
                    ['label' => $typeLabel]
                );

                if (!in_array($typeAlias, $typesSeen)) {
                    $typesSeen[] = $typeAlias;
                    $this->logger()->debug('Tag type created/found', ['alias' => $typeAlias, 'label' => $typeLabel, 'id' => $tagType->id]);
                }

                $tag = Tag::findOrCreateFromString($tagItem['name'], $tagType->id);
                $generatedTags[] = $tag;

                $this->logger()->debug('Tag saved', ['name' => $tag->name, 'type' => $typeAlias]);
            }

            $process->setComplete(['tags_generated' => count($generatedTags)]);

            $this->logger()->info('Tags generation job completed', [
                'tags_generated' => count($generatedTags),
                'types_created'  => count($typesSeen),
                'types'          => $typesSeen,
            ]);
        } catch (ModelNotFoundException $exception) {
            $this->logger()->warning('Process not found', ['process_id' => $this->processId]);
            $this->fail($exception);
        } catch (Throwable $e) {
            $this->logger()->error("Unexpected exception in {$this->getJobName()}", [
                'exception_class' => get_class($e),
                'message'         => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);
            $this->handleTemporaryFailure($e, $process ?? null);
        }
    }
}
