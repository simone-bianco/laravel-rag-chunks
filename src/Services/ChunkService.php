<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\DTOs\DocumentDTO;
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
        protected ?LoggerInterface $logger = null,
        protected ?HashService $hashService = null
    ) {
        $this->embeddingDriver ??= EmbeddingFactory::make();
        $this->logger ??= Log::channel('chunking');
        $this->hashService = app(HashService::class);
    }

    protected function getOrCreateDocument(DocumentDTO $dto): Document
    {
        return Document::firstOrCreate([
            'alias' => $dto->alias ?? Str::random(64),
        ], [
            'hash' => $dto->hash ?? $this->hashService->hash($dto->text),
            'name' => $dto->name ?? Str::limit($dto->text),
            'metadata' => $dto->metadata,
        ]);
    }

    /**
     * @throws Throwable
     */
    public function createChunks(DocumentDTO $documentData): Document
    {
        $document = null;

        try {
            $createdChunks = collect();
            $rawChunks = array_filter(str_split($documentData->text, $this->splitSize), function ($rawChunk) {
                return ! empty(trim($rawChunk));
            });

            if (empty($rawChunks)) {
                throw new ChunkingFailedException('Text is empty or not chunkable');
            }

            $rawChunksData = array_map(function ($rawChunk) {
                return [
                    'content' => $rawChunk,
                    'hash' => $this->hashService->hash($rawChunk),
                ];
            }, $rawChunks);

            /** @var Collection<string, Chunk> $existingChunks */
            $existingChunks = ChunkQueryFactory::make()
                ->select(['hash', 'content', 'embedding'])
                ->distinct()
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

            return Document::query()->getConnection()->transaction(function () use ($documentData, $createdChunks, &$document) {
                $document = $this->getOrCreateDocument($documentData);
                $document->chunks()->delete();
                $document->chunks()->saveMany($createdChunks->all());

                return $document;
            });
        } catch (Throwable $e) {
            $this->logger->error("Error during chunking: {$e->getMessage()}", [
                'trace' => $e->getTrace(),
                'document_id' => $document?->id ?? 'new',
            ]);

            throw new ChunkingFailedException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
