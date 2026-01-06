<?php

namespace SimoneBianco\LaravelRagChunks\Facades;

use Illuminate\Support\Facades\Facade;

class RagChunks extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-rag-chunks.factory';
    }
}
