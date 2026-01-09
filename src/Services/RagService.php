<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\DTOs\DocumentDTO;
use SimoneBianco\LaravelRagChunks\Exceptions\ChunkingFailedException;
use SimoneBianco\LaravelRagChunks\Models\Document;
use Throwable;

class RagService
{
    public function __construct(
        protected ?int $splitSize = null,
        protected ?LoggerInterface $logger = null,
        protected DocumentService $documentService
    ) {
        $this->splitSize ??= config('laravel-rag-chunks.split_size', 500);
        $this->logger ??= Log::channel('rag');
    }

    /**
     * @throws Throwable
     */
    public function addToRag(DocumentDTO $documentData): Document
    {
        try {
            $this->logger->info("RagService: processing document {$documentData->alias}");

            $document = Document::updateOrCreate(
                ['alias' => $documentData->alias],
                [
                    'content' => $documentData->text,
                    'name' => $documentData->name,
                    'description' => $documentData->description,
                    'hash' => $documentData->hash,
                    'metadata' => $documentData->metadata,
                ]
            );

            // use mb_str_split to avoid breaking characters
            $rawChunks = array_filter(mb_str_split($documentData->text, $this->splitSize), function ($rawChunk) {
                return ! empty(trim($rawChunk));
            });

            if (empty($rawChunks)) {
                throw new ChunkingFailedException('Text is empty or not chunkable');
            }

            return $this->documentService->regenerateChunks($documentData, $rawChunks);
        } catch (Throwable $e) {
            $this->logger->error("Error during chunking: {$e->getMessage()}", [
                'document_alias' => $documentData->alias,
            ]);

            throw new ChunkingFailedException($e->getMessage(), 0, $e);
        }


    }
}
