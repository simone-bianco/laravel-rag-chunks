<?php

namespace SimoneBianco\LaravelRagChunks\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string getTempDirPath()
 * @method static string generateTempPath(?string $extension = null)
 * 
 * @see \SimoneBianco\LaravelRagChunks\Services\FileService
 */
class FileService extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'rag-chunks-file';
    }
}
