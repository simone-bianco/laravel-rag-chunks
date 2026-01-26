<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SimoneBianco\LaravelSimpleTags\HasTags;

class Project extends Model
{
    use HasFactory, HasTags, HasUuids;

    protected static function newFactory()
    {
        return \SimoneBianco\LaravelRagChunks\Database\Factories\ProjectFactory::new();
    }

    protected $guarded = [];

    // protected $fillable removed in favor of guarded = []

    protected $casts = [
        'settings' => 'array',
    ];

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
