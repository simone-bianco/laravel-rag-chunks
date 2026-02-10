<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SimoneBianco\LaravelRagChunks\Database\Factories\ProjectFactory;
use SimoneBianco\LaravelSimpleTags\HasTags;

class Project extends Model
{
    use HasFactory, HasTags, HasUuids;

    protected static function newFactory()
    {
        return ProjectFactory::new();
    }

    protected $fillable = [
        'name',
        'description',
        'alias',
        'category',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function getTagsSlugsKeyedByTypes(): array
    {
        return $this->tags()
            ->select('type', 'slug')
            ->get()
            ->groupBy('type')
            ->mapWithKeys(function ($tags, $key) {
                return [$key => $tags->pluck('slug')->toArray()];
            })
            ->toArray();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function sharedDocuments(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_project_shares')
            ->withPivot('metadata')
            ->withTimestamps();
    }
}
