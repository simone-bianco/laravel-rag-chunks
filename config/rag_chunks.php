<?php

use SimoneBianco\LaravelRagChunks\Enums\EmbeddingDriver;

return [
    'embedding' => EmbeddingDriver::OPENAI,


    'semantic_weights' => [
        'content' => 0.7,
        'semantic_tags' => 0.3
    ],

    'semantic_tagger' => [
        'provider' => 'openai',
        'model' => 'gpt-4.1-nano'
    ],



    'embedders' => [
        EmbeddingDriver::OPENAI->value => [
            'model' => 'text-embedding-3-small',
            'api_key' => env('OPENAI_API_KEY'),
            'embedding_size' => 1536,
        ],
    ],
];
