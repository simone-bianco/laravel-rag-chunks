<?php

namespace SimoneBianco\LaravelRagChunks\Drivers\Embedding;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\Drivers\Embedding\Contracts\EmbeddingDriverInterface;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use Throwable;

class OpenaiEmbeddingDriver implements EmbeddingDriverInterface
{
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
                throw new ClientException('OPENAI_API_KEY not set in config.', 401, null, null, [], false);
            }

            $response = Http::withToken($this->apiKey)
                ->post($this->baseUrl, [
                    'model' => $this->model,
                    'input' => $text,
                ]);

            if ($response->failed()) {
                throw new ClientException(
                    'OpenAI Embedding Error: ' . $response->body(),
                    $response->status(),
                    null,
                    null,
                    $response->json() ?? ['raw_body' => $response->body()],
                );
            }

            $embedding = $response->json('data.0.embedding');
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
        $embeds = [];

        foreach ($texts as $text) {
            $embeds[] = $this->embed($text);
        }

        return $embeds;
    }
}
