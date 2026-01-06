<?php

namespace SimoneBianco\LaravelRagChunks\Factories;

use SimoneBianco\LaravelRagChunks\Enums\ChunkModel;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidChunkDriverException;
use SimoneBianco\LaravelRagChunks\Models\Chunk;

class ChunkFactory
{
    /**
     * @param ChunkModel|null $chunkDriver
     * @return Chunk
     * @throws InvalidChunkDriverException
     */
    public static function make(?ChunkModel $chunkDriver = null): Chunk
    {
        $chunkDriver ??= config('rag_chunks.driver');

        if ($chunkDriver === null) {
            throw new InvalidChunkDriverException('Default chunk driver not configured in rag_chunks.driver');
        }

        $driverClass = config("rag_chunks.models.{$chunkDriver->value}");

        if (! $driverClass || ! class_exists($driverClass)) {
            throw new InvalidChunkDriverException("Chunk driver class '{$driverClass}' for driver '{$chunkDriver->value}' not found.");
        }

        return new $driverClass();
    }
}
