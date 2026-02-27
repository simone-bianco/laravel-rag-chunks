<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

class ChunkFilterDataDTO
{
    public function __construct(
        public ?string $documentId = null,
        public ?string $text = null,
        public bool $caseSensitive = false,
        public ?int $charMin = null,
        public ?int $charMax = null,
        public ?bool $isDirty = null,
        public ?bool $hasEmbedding = null,
        /** @var array<string, int[]>|null */
        public ?array $chunkTagGroups = null,
        public int $page = 1,
        public int $perPage = 12,
        public ?string $semanticText = null,
        public ?string $semanticTags = null,
        public ?string $semanticQuestions = null,
    ) {}
}
