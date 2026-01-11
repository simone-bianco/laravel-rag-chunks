<?php

use SimoneBianco\LaravelRagChunks\Drivers\Embedding\OpenaiEmbeddingDriver;
use SimoneBianco\LaravelRagChunks\Enums\ChunkModel;
use SimoneBianco\LaravelRagChunks\Enums\EmbeddingDriver;
use SimoneBianco\LaravelRagChunks\Models\Chunk;

return [
    'driver' => ChunkModel::POSTGRES,
    'embedding' => EmbeddingDriver::OPENAI,

    'models' => [
        ChunkModel::POSTGRES->value => Chunk::class,
    ],

    'embedders' => [
        EmbeddingDriver::OPENAI->value => [
            'driver' => OpenaiEmbeddingDriver::class,
            'model' => 'text-embedding-3-small',
            'api_key' => env('OPENAI_API_KEY'),
            'embedding_size' => 1536,
        ],
    ],
];
