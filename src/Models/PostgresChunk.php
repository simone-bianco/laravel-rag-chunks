<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use SimoneBianco\LaravelRagChunks\Models\Contracts\ChunkContract;
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;
use Tpetry\PostgresqlEnhanced\Query\Builder;

class PostgresChunk extends Chunk implements ChunkContract
{
    protected $table = 'chunks';

    protected $guarded = [];

    protected $casts = [
        'embedding' => VectorArray::class,
        'metadata' => 'array',
    ];

    public function scopeNearest($query, array $vector): Builder
    {
        return $query->orderByRaw('embedding <-> ?', [json_encode($vector)]);
    }
}
