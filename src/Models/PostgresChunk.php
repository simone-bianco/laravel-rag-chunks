<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;
use Tpetry\PostgresqlEnhanced\Query\Builder;

class PostgresChunk extends Chunk
{
    protected $guarded = [];

    protected $casts = [
        'embedding' => VectorArray::class,
        'metadata'  => 'array',
    ];

    public function scopeNearest($query, array $vector): Builder
    {
        return $query->orderByRaw('embedding <-> ?', [json_encode($vector)]);
    }
}
