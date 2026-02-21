<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use App\Observers\TagObserver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SimoneBianco\LaravelProcesses\Models\Traits\HasProcesses;
use SimoneBianco\LaravelRagChunks\Enums\Process\ProcessType;
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;

class Tag extends \SimoneBianco\LaravelSimpleTags\Tag
{
    use HasProcesses;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'order_column',
        'project_id',
        'description',
        'description_embedding',
    ];

    protected static function booted(): void
    {
        parent::booted();
        static::observe(TagObserver::class);
    }

    public function getTaggableCountAttribute(): int
    {
        return DB::table('taggables')
            ->where('tag_id', $this->id)
            ->count();
    }

    protected function casts(): array
    {
        return [
            'description_embedding' => VectorArray::class,
        ];
    }

    public function scopeForProject(Builder $query, string $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isEmbedding(): bool
    {
        return $this->activeProcesses()->where('type', ProcessType::EMBEDDING)->count() >= 1;
    }
}
