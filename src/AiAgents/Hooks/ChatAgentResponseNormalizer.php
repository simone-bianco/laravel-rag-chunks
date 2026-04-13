<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents\Hooks;

use Illuminate\Support\Str;
use SimoneBianco\LaravelAiAgents\Contracts\AgentResponseHook;
use SimoneBianco\LaravelAiAgents\Models\AiAgent;
use SimoneBianco\LaravelAiAgents\Support\AgentRunContext;
use SimoneBianco\LaravelRagChunks\AiAgents\Concerns\NormalizesChunkIds;
use SimoneBianco\LaravelRagChunks\Models\Chunk;

final class ChatAgentResponseNormalizer implements AgentResponseHook
{
    use NormalizesChunkIds;

    public function handle(AiAgent $agent, mixed $rawResponse, AgentRunContext $context): mixed
    {
        if (! is_array($rawResponse)) {
            return [
                'response' => (string) $rawResponse,
                'relevant_chunks' => [],
                'relevant_images' => [],
            ];
        }

        $metadata = is_array($agent->metadata ?? null) ? $agent->metadata : [];
        $chatMeta = is_array($metadata['chat'] ?? null) ? $metadata['chat'] : [];
        $includeChunks = (bool) ($chatMeta['include_relevant_chunks'] ?? true);
        $includeImages = (bool) ($chatMeta['include_relevant_images'] ?? true);

        $chunkIdsRaw = is_array($rawResponse['relevant_chunks'] ?? null)
            ? $rawResponse['relevant_chunks']
            : [];
        $chunkIds = $this->normalizeChunkIds($chunkIdsRaw);

        $result = [
            'response' => (string) ($rawResponse['response'] ?? ''),
        ];

        if ($includeChunks) {
            $result['relevant_chunks'] = $chunkIds;
        }

        if ($includeImages) {
            $imagesRaw = is_array($rawResponse['relevant_images'] ?? null)
                ? $rawResponse['relevant_images']
                : [];
            $result['relevant_images'] = $this->normalizeRelevantImages($imagesRaw, $chunkIds);
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $images
     * @param array<int, string> $chunkIds
     * @return array<int, array{url: string, content: string}>
     */
    private function normalizeRelevantImages(array $images, array $chunkIds): array
    {
        $normalized = [];

        foreach ($images as $image) {
            if (is_string($image) && $image !== '') {
                $normalized[] = ['url' => $image, 'content' => 'Immagine rilevante'];
                continue;
            }

            if (! is_array($image)) {
                continue;
            }

            $url = isset($image['url']) && is_string($image['url']) ? trim($image['url']) : '';
            if ($url === '') {
                continue;
            }

            $content = isset($image['content']) && is_string($image['content'])
                ? trim($image['content'])
                : '';

            $normalized[] = [
                'url' => $url,
                'content' => $content !== '' ? $content : 'Immagine rilevante',
            ];
        }

        if (! empty($chunkIds)) {
            $chunks = Chunk::query()->whereIn('id', $chunkIds)->get();

            foreach ($chunks as $chunk) {
                $url = $chunk->getFirstMedia()?->getUrl();

                if (! is_string($url) || $url === '') {
                    continue;
                }

                $normalized[] = [
                    'url' => $url,
                    'content' => Str::limit(trim((string) $chunk->content), 120),
                ];
            }
        }

        return collect($normalized)->unique('url')->values()->toArray();
    }
}
