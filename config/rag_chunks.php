<?php

use SimoneBianco\LaravelRagChunks\Enums\ChunkingDriver;
use SimoneBianco\LaravelRagChunks\Enums\EmbeddingDriver;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;

return [
    'driver' => ChunkingDriver::POSTGRES,
    'embedding' => EmbeddingDriver::OPENAI,
    'embedding_cast' => VectorArray::class,

    'semantic_weights' => [
        'content' => 0.7,
        'semantic_tags' => 0.3
    ],

    'models' => [
        'chunk' => Chunk::class,
    ],

    'embedders' => [
        EmbeddingDriver::OPENAI->value => [
            'driver' => ChunkingDriver::POSTGRES,
            'model' => 'text-embedding-3-small',
            'api_key' => env('OPENAI_API_KEY'),
            'embedding_size' => 1536,
        ],
    ],
];
