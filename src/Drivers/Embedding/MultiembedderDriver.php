<?php

namespace SimoneBianco\LaravelRagChunks\Drivers\Embedding;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\Drivers\Embedding\Contracts\EmbeddingDriverInterface;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
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
     * @throws ClientException
     */
    public function embed(string $text): array
    {
        try {
            if (empty($this->apiKey)) {
                throw new ClientException('API key not set in config.', 401, null, null, [], false);
            }

            $url = $this->resolveUrl(self::EMBED_ENDPOINT);

            $response = Http::withToken($this->apiKey)
                ->post($url, [
                    'model' => $this->model,
                    'text' => $text,
                ]);

            if ($response->failed()) {
                throw new ClientException(
                    'Embedding Error: ' . $response->body(),
                    $response->status(),
                    null,
                    null,
                    $response->json() ?? ['raw_body' => $response->body()],
                );
            }

            // Fixed response parsing based on routes.py
            $embedding = $response->json('embedding');
            if (empty($embedding)) {
                throw new ClientException('Embedding is empty', 422, null, null, [], false);
            }

            return $embedding;
        } catch (ClientException $e) {
            $this->logger()->error("Error during embedding: {$e->getMessage()}", [
                'driver' => $this->configKey,
                'text' => $text,
                'model' => $this->model,
                ...$e->context(),
            ]);

            throw $e;
        } catch (Throwable $throwable) {
            $this->logger()->error("Error during embedding: {$throwable->getMessage()}", [
                'driver' => $this->configKey,
                'text' => $text,
                'model' => $this->model,
                'trace' => $throwable->getTrace(),
            ]);

            throw ClientException::makeFromException($throwable);
        }
    }

    /**
     * @param array $texts
     * @return array
     * @throws ClientException
     */
    public function multiEmbed(array $texts): array
    {
        try {
            if (empty($this->apiKey)) {
                throw new ClientException('API key not set in config.', 401, null, null, [], false);
            }

            $url = $this->resolveUrl(self::BATCH_EMBED_ENDPOINT);

            $response = Http::withToken($this->apiKey)
                ->post($url, [
                    'model' => $this->model,
                    'texts' => $texts,
                ]);

            if ($response->failed()) {
                throw new ClientException(
                    'Batch Embedding Error: ' . $response->body(),
                    $response->status(),
                    null,
                    null,
                    $response->json() ?? ['raw_body' => $response->body()],
                );
            }

            $embeddings = $response->json('embeddings');
            if (empty($embeddings)) {
                throw new ClientException('Embeddings are empty', 422, null, null, [], false);
            }

            return $embeddings;
        } catch (ClientException $e) {
            $this->logger()->error("Error during batch embedding: {$e->getMessage()}", [
                'driver' => $this->configKey,
                'count' => count($texts),
                'model' => $this->model,
                ...$e->context(),
            ]);

            throw $e;
        } catch (Throwable $throwable) {
            $this->logger()->error("Error during batch embedding: {$throwable->getMessage()}", [
                'driver' => $this->configKey,
                'count' => count($texts),
                'model' => $this->model,
                'trace' => $throwable->getTrace(),
            ]);

            throw ClientException::makeFromException($throwable);
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
