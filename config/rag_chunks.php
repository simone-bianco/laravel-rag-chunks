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
        'e5-large' => [
            'model' => 'e5-large',
            'api_key' => env('MULTI_EMBEDDER_API_KEY'),
            'base_url' => env('MULTI_EMBEDDER_BASE_URL', 'http://localhost:5000/api'),
            'embedding_size' => 1024,
        ],
        'bge-m3' => [
            'model' => 'bge-m3',
            'api_key' => env('MULTI_EMBEDDER_API_KEY'),
            'base_url' => env('MULTI_EMBEDDER_BASE_URL', 'http://localhost:5000/api'),
            'embedding_size' => 1024,
        ],
        'nomic' => [
            'model' => 'nomic',
            'api_key' => env('MULTI_EMBEDDER_API_KEY'),
            'base_url' => env('MULTI_EMBEDDER_BASE_URL', 'http://localhost:5000/api'),
            'embedding_size' => 768,
        ],
    ],

    'embedding_retry' => [
        'times' => 3,
        'sleep' => 1000,
    ],
];
