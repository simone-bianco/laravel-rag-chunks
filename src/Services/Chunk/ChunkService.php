<?php

namespace SimoneBianco\LaravelRagChunks\Services\Chunk;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\Exceptions\ChunkingFailedException;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidEmbeddingDriverException;
use SimoneBianco\LaravelRagChunks\Factories\ChunkFactory;
use SimoneBianco\LaravelRagChunks\Factories\ChunkQueryFactory;
use SimoneBianco\LaravelRagChunks\Factories\EmbeddingFactory;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\Embedding\Contracts\EmbeddingDriverInterface;
use Throwable;

class ChunkService
{
    /**
     * @throws InvalidEmbeddingDriverException
     */
    public function __construct(
        protected ?EmbeddingDriverInterface $embeddingDriver = null,
        protected int $splitSize = 1000,
        protected ?LoggerInterface $logger = null
    ) {
        $this->embeddingDriver ??= EmbeddingFactory::make();
        $this->logger ??= Log::channel('chunking');
    }

    protected function getOrCreateDocument(string $text): Document
    {
        return Document::firstOrCreate([
            'hash' => hash('sha256', $text),
        ], [
            'alias' => Str::random(64),
            'name' => Str::limit($text),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function createChunks(string $text, ?Document $document = null): Collection
    {
        try {
            $createdChunks = collect();
            $rawChunks = array_filter(str_split($text, $this->splitSize), function ($rawChunk) {
                return ! empty(trim($rawChunk));
            });

            if (empty($rawChunks)) {
                $document?->delete();

                return $createdChunks;
            }

            $rawChunksData = array_map(function ($rawChunk) {
                return [
                    'content' => $rawChunk,
                    'hash' => hash('sha256', $rawChunk),
                ];
            }, $rawChunks);

            /** @var Collection<string, Chunk> $existingChunks */
            $existingChunks = ChunkQueryFactory::make()
                ->select(['id', 'hash', 'content', 'embedding'])
                ->whereIn('hash', Arr::pluck($rawChunksData, 'hash'))
                ->get()
                ->keyBy('hash');

            foreach ($rawChunksData as $index => $data) {
                $hash = $data['hash'];

                if ($existingChunks->has($hash)) {
                    $existingChunk = $existingChunks->get($hash);
                    $chunk = ChunkFactory::make()->fill([
                        'content' => $existingChunk->content,
                        'hash' => $existingChunk->hash,
                        'embedding' => $existingChunk->embedding,
                    ]);
                } else {
                    $chunk = ChunkFactory::make()->fill([
                        'content' => $data['content'],
                        'hash' => $hash,
                        'embedding' => $this->embeddingDriver->embed($data['content']),
                    ]);
                }

                $chunk->page = $index + 1;
                $createdChunks->push($chunk);
            }

            $document ??= $this->getOrCreateDocument($text);

            $document->getConnection()->transaction(function () use ($document, $createdChunks) {
                $document->chunks()->delete();
                $document->chunks()->saveMany($createdChunks->all());
            });

            return $createdChunks;

        } catch (Throwable $e) {
            $this->logger->error("Error during chunking: {$e->getMessage()}", [
                'trace' => $e->getTrace(),
                'document_id' => $document?->id ?? 'new',
            ]);

            throw new ChunkingFailedException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
