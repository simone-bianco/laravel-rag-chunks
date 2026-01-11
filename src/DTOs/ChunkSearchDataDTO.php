<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

readonly class ChunkSearchDataDTO
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 100,
        public ?string $search = null,
        public ?array $projectsAliases = null,
        public ?\Illuminate\Support\Collection $tagFilters = null, // Collection<TagFilterDTO>
        public ?array $documentsAliases = null,
        public ?array $chunksIds = null,
    ) {
        $this->tagFilters ??= collect();
    }
}
