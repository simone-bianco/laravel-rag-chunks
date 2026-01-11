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

        $config = config("rag_chunks.embedders.{$embeddingDriver->value}");

        $driverClass = match($embeddingDriver) {
            EmbeddingDriver::OPENAI => \SimoneBianco\LaravelRagChunks\Drivers\Embedding\OpenaiEmbeddingDriver::class,
            EmbeddingDriver::OLLAMA => \SimoneBianco\LaravelRagChunks\Drivers\Embedding\OllamaEmbeddingDriver::class, // Assuming Ollama exists or will exist suited for pattern
            default => null,
        };

        if (is_array($config) && isset($config['class'])) {
            $driverClass = $config['class'];
        } elseif (is_string($config) && class_exists($config)) {
            $driverClass = $config;
        }

        if (! $driverClass || ! class_exists($driverClass)) {
            throw new InvalidEmbeddingDriverException("Embedding driver class '{$driverClass}' for driver '{$embeddingDriver->value}' not found.");
        }

        return app($driverClass);
    }
}
