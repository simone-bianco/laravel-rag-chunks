<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'name_embedding',
        'description_embedding',
        'metadata',
    ];

    protected function casts()
    {
        return [
            'enabled' => 'boolean',
            'metadata' => 'array',
            'description_embedding' => VectorArray::class,
            'name_embedding' => VectorArray::class,
        ];
    }

    /**
     * @return string
     * @throws FileNotFoundException
     */
    public function getAbsolutePath(): string
    {
        $path = Storage::path($this->file_path);
        if (!file_exists($path)) {
            throw new FileNotFoundException("Document $this->alias not found at $path");
        }

        return $path;
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function delete(): ?bool
    {
        if ($this->file_path && Storage::exists($this->file_path)) {
            Storage::delete($this->file_path);
        }

        return parent::delete();
    }
}
