<?php

namespace SimoneBianco\LaravelRagChunks\Services\Embedding;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\Enums\EmbeddingDriver;
use SimoneBianco\LaravelRagChunks\Exceptions\EmbeddingFailedException;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidCredentialsException;
use SimoneBianco\LaravelRagChunks\Services\Embedding\Contracts\EmbeddingDriverInterface;
use Throwable;

class OpenaiEmbeddingDriver implements EmbeddingDriverInterface
{
    protected const string OPENAI_EMBEDDING_URL = 'https://api.openai.com/v1/embeddings';

    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $model = null,
        protected ?LoggerInterface $logger = null
    ) {
        $this->apiKey ??= config('services.openai.key');
        $this->model ??= config('services.openai.embedding.model', 'text-embedding-3-small');
        $this->logger ??= Log::channel('embedding');
    }

    public function refreshApiKey(): self
    {
        $this->apiKey = config('services.openai.key');

        return $this;
    }

    /**
     * @param string $text
     * @return array
     *
     * @throws EmbeddingFailedException
     */
    public function embed(string $text): array
    {
        try {
            if (empty($this->apiKey)) {
                throw new InvalidCredentialsException('OPENAI_API_KEY not set in config.');
            }

            $response = Http::withToken($this->apiKey)
                ->post(self::OPENAI_EMBEDDING_URL, [
                    'model' => $this->model,
                    'input' => $text,
                ]);

            if ($response->failed()) {
                throw new EmbeddingFailedException('OpenAI Embedding Error: ' . $response->body());
            }

            $embedding = $response->json('data.0.embedding');
            if (empty($embedding)) {
                throw new EmbeddingFailedException('Embedding is empty');
            }

            return $response->json('data.0.embedding');
        } catch (Throwable $throwable) {
            $this->logger->error("Error during embedding: {$throwable->getMessage()}", [
                'driver' => EmbeddingDriver::OPENAI->value,
                'text' => $text,
                'model' => $this->model,
                'trace' => $throwable->getTrace()
            ]);

            throw new EmbeddingFailedException("Error during embedding: {$throwable->getMessage()}");
        }
    }
}
