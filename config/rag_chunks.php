<?php

use SimoneBianco\LaravelRagChunks\Enums\ChunkModel;
use SimoneBianco\LaravelRagChunks\Enums\EmbeddingDriver;
use SimoneBianco\LaravelRagChunks\Models\PostgresChunk;
use SimoneBianco\LaravelRagChunks\Services\Embedding\OpenaiEmbeddingDriver;

return [
    'driver' => ChunkModel::POSTGRES,
    'embedding' => EmbeddingDriver::OPENAI,

    'models' => [
        ChunkModel::POSTGRES->value => PostgresChunk::class
    ],

    'embedders' => [
        EmbeddingDriver::OPENAI->value => OpenaiEmbeddingDriver::class
    ]
];
