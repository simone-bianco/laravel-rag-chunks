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
            'batch_size' => 8,
            'chunks_in_schema' => false
        ],
        'image_postprocessor' => [
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'preferred_chunk_length' => 600,
            'pdftoppm_binary' => env('RAG_PDFTOPPM_BINARY', 'pdftoppm'),
            'pdftotext_binary' => env('RAG_PDFTOTEXT_BINARY', 'pdftotext'),
            'pdftocairo_binary' => env('RAG_PDFTOCAIRO_BINARY', 'pdftocairo'),
            'pdf_render_dpi' => (int) env('RAG_PDF_RENDER_DPI', 150),
            'pdf_render_timeout_seconds' => (int) env('RAG_PDF_RENDER_TIMEOUT_SECONDS', 2400),
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
            'timeout' => env('MULTI_EMBEDDER_TIMEOUT', 300),
        ],
    ],

    'embedding_retry' => [
        'times' => 3,
        'sleep' => 1000,
    ],

    /*
     * Number of characters to overlap at batch boundaries.
     * The first chunk's agent call receives this many trailing chars from the previous batch,
     * and the last chunk receives this many leading chars from the next batch.
     * Set to 0 to disable overlap entirely.
     */
    'chunk_overlap' => 50,

    'knowledge_graph' => [
        'projection_url'  => env('MULTI_EMBEDDER_URL', 'http://localhost:5000'),
        'projection_key'  => env('MULTI_EMBEDDER_API_KEY'),
        'max_graph_edges' => 15000,
        'cache_ttl'       => 3600,
    ],
];
