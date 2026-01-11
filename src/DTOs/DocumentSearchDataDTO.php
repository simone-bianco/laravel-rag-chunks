<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

class DocumentSearchDataDTO
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 100,
        public ?array $projectsAliases = null,
        public ?\Illuminate\Support\Collection $tagFilters = null, // Collection<TagFilterDTO>
        public ?array $documentsAliases = null, // aliases of documents to search for
        public ?string $documentNameSearch = null,
        public ?string $documentDescriptionSearch = null,
    ) {
        $this->tagFilters ??= collect();
    }
}
