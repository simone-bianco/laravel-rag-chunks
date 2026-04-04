<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SimoneBianco\LaravelProcesses\Models\Traits\HasProcesses;
use SimoneBianco\LaravelRagChunks\Traits\HasNearestNeighbors;
use SimoneBianco\LaravelSimpleTags\HasTags;
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;

class Document extends Model
{
    use HasNearestNeighbors, HasTags, HasUuids, HasProcesses;

    protected $fillable = [
        'project_id',
        'name',
        'extension',
        'enabled',
        'description',
        'alias',
        'hash',
        'file_path',
        'size',
        'disk',
        'name_embedding',
        'description_embedding',
        'semantic_tags',
        'questions',
        'semantic_tags_embedding',
        'questions_embedding',
        'metadata',
        'is_chunks_dirty',
        'original_file_path',
        'original_extension',
    ];

    protected function casts()
    {
        return [
            'enabled' => 'boolean',
            'metadata' => 'array',
            'semantic_tags' => 'array',
            'questions' => 'array',
            'description_embedding' => VectorArray::class,
            'name_embedding' => VectorArray::class,
            'semantic_tags_embedding' => VectorArray::class,
            'questions_embedding' => VectorArray::class,
            'is_chunks_dirty' => 'boolean',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    /** Chunks that have been indexed (have a non-empty chapter). */
    public function indexedChunks(): HasMany
    {
        return $this->hasMany(Chunk::class)
            ->whereNotNull('chapter')
            ->where('chapter', '!=', '');
    }

    /** Processes that are currently active (pending or processing). */
    public function activeProcesses()
    {
        return $this->processes()->whereIn('status', ['pending', 'processing']);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function purgeChunks(): self
    {
        Chunk::where('document_id', $this->id)->delete();
        return $this;
    }

    public function delete(): ?bool
    {
        if ($this->file_path && Storage::disk($this->disk ?? 'local')->exists($this->file_path)) {
            Storage::disk($this->disk ?? 'local')->delete($this->file_path);
        }

        return parent::delete();
    }

    public function getAbsolutePath(): string
    {
        return Storage::disk($this->disk ?? 'local')->path($this->file_path);
    }

    public function getAbsolutePathAttribute(): string
    {
        return $this->getAbsolutePath();
    }
}
