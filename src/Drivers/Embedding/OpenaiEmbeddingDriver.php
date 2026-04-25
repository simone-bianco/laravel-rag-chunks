<?php

namespace SimoneBianco\LaravelRagChunks\Drivers\Embedding;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelAiAgents\Concerns\InjectsRotatedOpenAIKey;
use SimoneBianco\LaravelRagChunks\Drivers\Embedding\Contracts\EmbeddingDriverInterface;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use Throwable;

class OpenaiEmbeddingDriver implements EmbeddingDriverInterface
{
    use InjectsRotatedOpenAIKey;

    public function __construct(
        protected string $configKey,
        protected ?string $baseUrl = null,
        protected ?string $apiKey = null,
        protected ?string $model = null,
    ) {
        $this->baseUrl ??= config("rag_chunks.embedders.$configKey.base_url");
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
        return $this->multiEmbed([$text])[0] ?? [];
    }

    /**
     * @param array $texts
     * @return array
     * @throws ClientException
     */
    public function multiEmbed(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        try {
            $this->injectRotatedOpenAIKey();

            $this->apiKey = config("rag_chunks.embedders.$this->configKey.api_key");

            if (empty($this->apiKey)) {
                throw new ClientException('OPENAI_API_KEY not set in config.', 401, null, null, [], false);
            }

            $response = Http::withToken($this->apiKey)
                ->post($this->baseUrl, [
                    'model' => $this->model,
                    'input' => array_values($texts),
                ]);

            if ($response->failed()) {
                throw new ClientException(
                    'OpenAI Batch Embedding Error: ' . $response->body(),
                    $response->status(),
                    null,
                    null,
                    $response->json() ?? ['raw_body' => $response->body()],
                );
            }

            $data = $response->json('data');
            if (! is_array($data) || empty($data)) {
                throw new ClientException('Embeddings are empty', 422, null, null, [], false);
            }

            $embeddings = [];
            foreach ($data as $item) {
                $index = $item['index'] ?? null;
                $embedding = $item['embedding'] ?? null;

                if (! is_int($index) || empty($embedding)) {
                    continue;
                }

                $embeddings[$index] = $embedding;
            }

            ksort($embeddings);

            if (count($embeddings) !== count($texts)) {
                throw new ClientException('Embedding count mismatch', 422, null, null, [
                    'expected_count' => count($texts),
                    'actual_count' => count($embeddings),
                ], false);
            }

            return array_values($embeddings);
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
}
