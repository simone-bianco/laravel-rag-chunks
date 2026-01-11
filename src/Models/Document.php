<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SimoneBianco\LaravelRagChunks\Enums\ChunkModel;
use SimoneBianco\LaravelRagChunks\Traits\HasNearestNeighbors;
use SimoneBianco\LaravelSimpleTags\HasTags;
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;

class Document extends Model
{
    use HasNearestNeighbors, HasTags, HasUuids;

    protected ChunkModel $driver;

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
    // protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        $this->driver = config('rag_chunks.driver', ChunkModel::POSTGRES);
        parent::__construct($attributes);
    }

    protected function casts()
    {
        $embedCast = $this->driver === ChunkModel::POSTGRES ? VectorArray::class : 'array';

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
