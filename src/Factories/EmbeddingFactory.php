<?php

namespace SimoneBianco\LaravelRagChunks\Factories;

use SimoneBianco\LaravelRagChunks\Enums\EmbeddingDriver;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidEmbeddingDriverException;
use SimoneBianco\LaravelRagChunks\Drivers\Embedding\Contracts\EmbeddingDriverInterface;

class EmbeddingFactory
{
    /**
     * @param EmbeddingDriver|null $embeddingDriver
     * @return EmbeddingDriverInterface
     * @throws InvalidEmbeddingDriverException
     */
    public static function make(?EmbeddingDriver $embeddingDriver = null): EmbeddingDriverInterface
    {
        $embeddingDriver ??= config('rag_chunks.embedding');

        if ($embeddingDriver === null) {
            throw new InvalidEmbeddingDriverException('Default embedding driver not configured in rag_chunks.embedding');
        }

        $driverClass = config("rag_chunks.embedders.{$embeddingDriver->value}");

        if (! $driverClass || ! class_exists($driverClass)) {
            throw new InvalidEmbeddingDriverException("Embedding driver class '{$driverClass}' for driver '{$embeddingDriver->value}' not found.");
        }

        return app($driverClass);
    }
}
