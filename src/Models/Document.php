<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SimoneBianco\LaravelRagChunks\Enums\ChunkingDriver;
use SimoneBianco\LaravelRagChunks\Traits\HasNearestNeighbors;
use SimoneBianco\LaravelSimpleTags\HasTags;
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;

class Document extends Model
{
    use HasNearestNeighbors, HasTags, HasUuids;

    protected ChunkingDriver $driver;

    protected $fillable = [
        'project_id',
        'name',
        'description',
        'alias',
        'hash',
        'path',
        'name_embedding',
        'description_embedding',
        'metadata',
    ];

    protected function casts()
    {
        $embedCast = config('rag_chunks.embedding_cast', VectorArray::class);

        return [
            'metadata' => 'array',
            'description_embedding' => $embedCast,
            'name_embedding' => $embedCast,
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
