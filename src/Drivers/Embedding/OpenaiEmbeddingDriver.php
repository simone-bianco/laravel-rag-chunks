<?php

namespace SimoneBianco\LaravelRagChunks\Drivers\Embedding;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\Drivers\Embedding\Contracts\EmbeddingDriverInterface;
use SimoneBianco\LaravelRagChunks\Exceptions\EmbeddingFailedException;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidCredentialsException;
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
     * @throws EmbeddingFailedException
     */
    public function embed(string $text): array
    {
        try {
            if (empty($this->apiKey)) {
                throw new InvalidCredentialsException('OPENAI_API_KEY not set in config.');
            }

            $response = Http::withToken($this->apiKey)
                ->post($this->baseUrl, [
                    'model' => $this->model,
                    'input' => $text,
                ]);

            if ($response->failed()) {
                throw new EmbeddingFailedException('OpenAI Embedding Error: '.$response->body());
            }

            $embedding = $response->json('data.0.embedding');
            if (empty($embedding)) {
                throw new EmbeddingFailedException('Embedding is empty');
            }

            return $response->json('data.0.embedding');
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
        $embeds = [];

        foreach ($texts as $text) {
            $embeds[] = $this->embed($text);
        }

        return $embeds;
    }
}
