<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Support\Facades\DB;
use SimoneBianco\LaravelRagChunks\DTOs\ProjectDTO;
use SimoneBianco\LaravelRagChunks\Models\Project;
use SimoneBianco\LaravelRagChunks\Models\TagsBlueprint;

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

            $tagsByType = $tagsBlueprint->tags_by_type; // is a JSON

            foreach ($tagsByType as $type => $tags) {
                // Ensure tags is an array
                if (is_array($tags)) {
                     // Sort tags alphabetically before attaching?
                     // User said "in seeder order tags", but logic here just attaches what is in blueprint.
                     // The blueprint should already be sorted.
                    $project->attachTags($tags, $type);
                }
            }

            DB::commit();

            return $project;
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    public function getByAlias(string $alias): Project
    {
        return Project::where('alias', $alias)->firstOrFail();
    }
}
