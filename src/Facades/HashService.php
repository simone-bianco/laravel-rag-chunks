<?php

namespace SimoneBianco\LaravelRagChunks\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string hash(string $text)
 * @method static string hashFile(string $absoluteFilePath)
 *
 * @see \SimoneBianco\LaravelRagChunks\Services\HashService
 */
class HashService extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'rag-chunks-hash';
    }
}
