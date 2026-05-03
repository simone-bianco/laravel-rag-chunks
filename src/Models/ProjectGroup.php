<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use SimoneBianco\LaravelProcesses\Models\Traits\HasProcesses;
use SimoneBianco\LaravelSimpleTags\HasTags;

class ProjectGroup extends Model
{
    use HasFactory, HasProcesses, HasTags, HasUuids;

    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * The projects that belong to this group.
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'group_project')
            ->withTimestamps();
    }

    /**
     * All documents aggregated from all projects in this group.
     * Useful for RAG: $group->documents() gives the full knowledge pool.
     */
    public function documents(): Builder
    {
        return Document::query()
            ->whereIn('project_id', $this->projects()->select('projects.id'));
    }

    public function getDocumentsAttribute(): Collection
    {
        return $this->documents()->get();
    }
}
