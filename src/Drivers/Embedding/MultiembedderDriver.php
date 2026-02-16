<?php

namespace SimoneBianco\LaravelRagChunks\Drivers\Embedding;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\Drivers\Embedding\Contracts\EmbeddingDriverInterface;
use SimoneBianco\LaravelRagChunks\Exceptions\EmbeddingFailedException;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidCredentialsException;
use Throwable;

class MultiembedderDriver implements EmbeddingDriverInterface
{
    private const string EMBED_ENDPOINT = '/embed';
    private const string BATCH_EMBED_ENDPOINT = '/batch_embed';

    public function __construct(
        protected string $configKey,
        protected ?string $baseUrl = null,
        protected ?string $apiKey = null,
        protected ?string $model = null,
    ) {
        $this->baseUrl ??= config("rag_chunks.embedders.$configKey.base_url");
        $this->apiKey ??= config("rag_chunks.embedders.$configKey.api_key");
        $this->model ??= config("rag_chunks.embedders.$configKey.model");
    }

    protected function logger(): LoggerInterface
    {
        return Log::channel('embedding');
    }

    /**
     * @throws EmbeddingFailedException
     */
    public function embed(string $text): array
    {
        try {
            if (empty($this->apiKey)) {
                throw new InvalidCredentialsException('API key not set in config.');
            }

            $url = $this->resolveUrl(self::EMBED_ENDPOINT);

            $response = Http::withToken($this->apiKey)
                ->post($url, [
                    'model' => $this->model,
                    'text' => $text,
                ]);

            if ($response->failed()) {
                throw new EmbeddingFailedException('Embedding Error: ' . $response->body());
            }

            // Fixed response parsing based on routes.py
            $embedding = $response->json('embedding');
            if (empty($embedding)) {
                throw new EmbeddingFailedException('Embedding is empty');
            }

            return $embedding;
        } catch (Throwable $throwable) {
            $this->logger()->error("Error during embedding: {$throwable->getMessage()}", [
                'driver' => $this->configKey,
                'text' => $text,
                'model' => $this->model,
                'trace' => $throwable->getTrace(),
            ]);

            throw new EmbeddingFailedException("Error during embedding: {$throwable->getMessage()}");
        }
    }

    /**
     * @param array $texts
     * @return array
     * @throws EmbeddingFailedException
     */
    public function multiEmbed(array $texts): array
    {
        try {
            if (empty($this->apiKey)) {
                throw new InvalidCredentialsException('API key not set in config.');
            }

            $url = $this->resolveUrl(self::BATCH_EMBED_ENDPOINT);

            $response = Http::withToken($this->apiKey)
                ->post($url, [
                    'model' => $this->model,
                    'texts' => $texts,
                ]);

            if ($response->failed()) {
                throw new EmbeddingFailedException('Embedding Error: ' . $response->body());
            }

            $embeddings = $response->json('embeddings');
            if (empty($embeddings)) {
                throw new EmbeddingFailedException('Embeddings are empty');
            }

            return $embeddings;
        } catch (Throwable $throwable) {
            $this->logger()->error("Error during batch embedding: {$throwable->getMessage()}", [
                'driver' => $this->configKey,
                'count' => count($texts),
                'model' => $this->model,
                'trace' => $throwable->getTrace(),
            ]);

            throw new EmbeddingFailedException("Error during batch embedding: {$throwable->getMessage()}");
        }
    }

    private function resolveUrl(string $endpoint): string
    {
        $baseUrl = rtrim($this->baseUrl, '/');

        if (str_ends_with($baseUrl, self::EMBED_ENDPOINT)) {
            $baseUrl = substr($baseUrl, 0, -strlen(self::EMBED_ENDPOINT));
        } elseif (str_ends_with($baseUrl, self::BATCH_EMBED_ENDPOINT)) {
            $baseUrl = substr($baseUrl, 0, -strlen(self::BATCH_EMBED_ENDPOINT));
        }

        return $baseUrl . $endpoint;
    }
}
