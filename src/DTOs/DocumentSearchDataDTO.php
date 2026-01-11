<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

readonly class DocumentSearchDataDTO
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 100,
        public ?string $project_alias = null,
        public ?\Illuminate\Support\Collection $tagFilters = null, // Collection<TagFilterDTO>
        public ?array $aliases = null, // aliases of documents to search for
        public ?string $name = null,
        public ?string $description = null,
    ) {
        $this->tagFilters ??= collect();
    }
}
