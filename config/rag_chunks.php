<?php

use SimoneBianco\LaravelRagChunks\Enums\EmbeddingDriver;

return [
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

    'embedding' => 'openai',

    'embedders' => [
        'openai' => [
            'model' => 'text-embedding-3-small',
            'api_key' => env('OPENAI_API_KEY'),
            'embedding_size' => 1536,
        ],
        'multiembedder' => [
            'model' => 'e5-large',
            'api_key' => env('MULTI_EMBEDDER_API_KEY'),
            'base_url' => env('MULTI_EMBEDDER_BASE_URL', 'http://localhost:5000/api'),
            'embedding_size' => 1024,
        ],
    ],

    'embedding_retry' => [
        'times' => 3,
        'sleep' => 1000,
    ],
];
