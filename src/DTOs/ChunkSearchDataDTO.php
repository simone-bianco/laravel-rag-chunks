<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

readonly class ChunkSearchDataDTO
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 100,
        public string $search,
        public ?array $anyTags = null,
        public ?array $allTags = null,
        public ?array $anyTagsByType = null,
        public ?array $allTagsByType = null,
        public ?array $documentsIds = null,
    ) {}
}
