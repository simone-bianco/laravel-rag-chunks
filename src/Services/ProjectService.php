<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SimoneBianco\LaravelProcesses\Enums\ProcessStatus;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\DTOs\ProjectDTO;
use SimoneBianco\LaravelRagChunks\Enums\Process\ProcessType;
use SimoneBianco\LaravelRagChunks\Models\Project;
use SimoneBianco\LaravelRagChunks\Models\Tag;
use SimoneBianco\LaravelRagChunks\Models\TagsBlueprint;
use Throwable;

class ProjectService
{
    public function __construct(protected TagService $tagService)
    {
    }

    public function create(ProjectDTO $projectData): Project
    {
        try {
            DB::beginTransaction();

            $project = new Project();
            $project->fill([
                'name' => $projectData->name,
                'description' => $projectData->description,
                'alias' => $projectData->alias,
                'settings' => $projectData->settings,
            ]);
            $project->save();

            $tagsBlueprint = TagsBlueprint::query()
                ->select('id', 'tags_by_type')
                ->where('alias', $projectData->tagsBlueprintAlias)
                ->firstOrFail();

            $tagsByType = $tagsBlueprint->tags_by_type;

            foreach ($tagsByType as $type => $tags) {
                $project->attachTags($tags, $type);
            }

            DB::commit();

            return $project;
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    public function getByAlias(string $alias): Project
    {
        return Project::where('alias', $alias)->firstOrFail();
    }

    /**
     * @param Project $project
     * @return Collection
     * @throws Throwable
     */
    public function dispatchTagsEmbed(Project $project): Collection
    {
        $allTags = Tag::where('project_id', $project->id)->get();
        $tagIds = $allTags->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        [$tagsWithActiveProcess, $tagsWithErrorProcess] = Process::where('processable_type', Tag::class)
            ->whereIn('processable_id', $tagIds)
            ->where('type', ProcessType::EMBEDDING->value)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('processable_id')
            ->reduce(function ($carry, $processes, $processableId) {
                $id = (int) $processableId;

                // 1. Se c'è un processo attivo, lo infiliamo nel primo array ($carry[0])
                if ($processes->contains(fn ($p) => in_array($p->status, [ProcessStatus::PENDING, ProcessStatus::PROCESSING]))) {
                    $carry[0][] = $id;
                }

                // 2. Se il più recente è in errore, lo infiliamo nel secondo array ($carry[1])
                if ($processes->first()->status === ProcessStatus::ERROR) {
                    $carry[1][] = $id;
                }

                return $carry;
            }, [[], []]); // <-- Qui inizializziamo i due array vuoti!

        // Tags that need embedding: no embedding OR latest process is error, excluding currently active
        $tagsToEmbed = $allTags->filter(function (Tag $tag) use ($tagsWithActiveProcess, $tagsWithErrorProcess) {
            if (in_array($tag->id, $tagsWithActiveProcess)) {
                return false;
            }

            return is_null($tag->description_embedding) || in_array($tag->id, $tagsWithErrorProcess);
        });

        if ($tagsToEmbed->isEmpty()) {
            return collect();
        }

        return $this->tagService->dispatchMassEmbed($tagsToEmbed);
    }
}
