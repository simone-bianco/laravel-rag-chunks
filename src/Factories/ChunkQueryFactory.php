<?php

namespace SimoneBianco\LaravelRagChunks\Factories;

use Illuminate\Database\Eloquent\Builder;
use SimoneBianco\LaravelRagChunks\Enums\ChunkModel;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidChunkDriverException;
use SimoneBianco\LaravelRagChunks\Models\PostgresChunk;

class ChunkQueryFactory
{
    /**
     * @param ChunkModel|null $chunkDriver
     * @return Builder
     * @throws InvalidChunkDriverException
     */
    public static function make(?ChunkModel $chunkDriver = null): Builder
    {
        $chunkDriver ??= config('rag_chunks.driver');

        $driverClass = config("rag_chunks.models.{$chunkDriver->value}");

        if (! $driverClass || ! class_exists($driverClass)) {
            throw new InvalidChunkDriverException("Chunk driver class '{$driverClass}' for driver '{$chunkDriver->value}' not found.");
        }

        return $driverClass::query();
    }
}
