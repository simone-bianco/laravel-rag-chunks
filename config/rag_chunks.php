<?php

use SimoneBianco\LaravelRagChunks\Enums\EmbeddingDriver;

return [
    'embedding' => EmbeddingDriver::OPENAI,

    'semantic_weights' => [
        'content' => 0.7,
        'questions' => 0.7,
        'tags' => 0.3
    ],

    'agents' => [
        'postprocessor' => [
            'provider' => 'openai',
            'model' => 'gpt-4.1-nano',
            'chunks_in_schema' => false
        ],
    ],

    'embedders' => [
        'openai' => [
            'model' => 'text-embedding-3-small',
            'api_key' => env('OPENAI_API_KEY'),
            'embedding_size' => 1536,
        ],
    ],

    'embedding_retry' => [
        'times' => 3,
        'sleep' => 1000,
    ],
];
