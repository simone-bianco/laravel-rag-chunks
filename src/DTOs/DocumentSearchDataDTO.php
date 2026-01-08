<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

use SimoneBianco\LaravelRagChunks\Enums\TagFilterMode;

readonly class DocumentSearchDataDTO
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 100,
        public ?string $alias = null,
        public ?string $name = null,
        public ?string $description = null,
        public float $minSimilarity = 0.0,
        public ?array $anyTags = null,
        public ?array $allTags = null,
        public ?array $anyTagsByType = null,
        public ?array $allTagsByType = null,
    ) {}
}
