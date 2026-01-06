<?php

namespace SimoneBianco\LaravelRagChunks\Tests\Models;

use SimoneBianco\LaravelRagChunks\Models\Chunk;

class TestChunk extends Chunk
{
    protected $table = 'chunks';

    protected $guarded = [];

    protected $casts = [
        'embedding' => 'array',
        'metadata' => 'array',
    ];
}
