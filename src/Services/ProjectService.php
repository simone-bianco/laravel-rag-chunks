<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Support\Facades\DB;
use SimoneBianco\LaravelRagChunks\DTOs\ProjectDTO;
use SimoneBianco\LaravelRagChunks\Models\Project;
use SimoneBianco\LaravelRagChunks\Models\TagsBlueprint;
use Throwable;

class ProjectService
{
    public function create(ProjectDTO $projectData): Project
    {
        try {
            DB::beginTransaction();

            $project = new Project();
            $project->fill([
                'name' => $projectData->name,
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
}
