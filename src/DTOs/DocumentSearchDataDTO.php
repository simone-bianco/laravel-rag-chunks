<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

readonly class DocumentSearchDataDTO
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 100,
        public ?array $aliases = null,
        public ?string $name = null,
        public ?string $description = null,
        public ?array $anyTags = null,
        public ?array $allTags = null,
        public ?array $anyTagsByType = null,
        public ?array $allTagsByType = null,
    ) {}
}
