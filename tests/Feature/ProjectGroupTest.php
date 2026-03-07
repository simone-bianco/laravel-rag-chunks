<?php

namespace SimoneBianco\LaravelRagChunks\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SimoneBianco\LaravelRagChunks\Models\Project;
use SimoneBianco\LaravelRagChunks\Models\ProjectGroup;
use SimoneBianco\LaravelRagChunks\Tests\TestCase;

class ProjectGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_a_project_group(): void
    {
        $group = ProjectGroup::create([
            'name' => 'Lore Avanzato',
            'description' => 'Gruppo per i project di lore avanzato del GdR.',
        ]);

        $this->assertDatabaseHas('project_groups', [
            'name' => 'Lore Avanzato',
        ]);

        $this->assertNotNull($group->id);
    }

    public function test_can_attach_projects_to_a_group(): void
    {
        $group = ProjectGroup::create(['name' => 'Gruppo Alpha']);

        $project = Project::factory()->create();

        $group->projects()->attach($project->id);

        $this->assertDatabaseHas('group_project', [
            'project_group_id' => $group->id,
            'project_id' => $project->id,
        ]);

        $this->assertCount(1, $group->fresh()->projects);
    }

    public function test_project_can_belong_to_multiple_groups(): void
    {
        $groupA = ProjectGroup::create(['name' => 'Gruppo A']);
        $groupB = ProjectGroup::create(['name' => 'Gruppo B']);
        $project = Project::factory()->create();

        $project->groups()->attach([$groupA->id, $groupB->id]);

        $this->assertCount(2, $project->fresh()->groups);
    }

    public function test_unique_constraint_prevents_duplicate_pivot_entry(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $group = ProjectGroup::create(['name' => 'Gruppo Unico']);
        $project = Project::factory()->create();

        $group->projects()->attach($project->id);
        $group->projects()->attach($project->id); // deve lanciare eccezione
    }

    public function test_group_documents_helper_returns_aggregated_documents(): void
    {
        // Verifica che la relazione hasManyThrough sia accessibile
        $group = ProjectGroup::create(['name' => 'Gruppo Documenti']);
        $project = Project::factory()->create();

        $group->projects()->attach($project->id);

        // Senza documenti aggiunti, deve ritornare zero
        $this->assertCount(0, $group->documents);
    }
}
