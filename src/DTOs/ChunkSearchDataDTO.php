<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

use Illuminate\Support\Collection;

class ChunkSearchDataDTO
{
    public function __construct(
        public int         $page = 1,
        public int         $perPage = 100,
        public ?string     $search = null,
        public ?string     $textSearch = null,
        public ?string     $semanticTagsSearch = null,
        public ?float      $weightContent = null,
        public ?float      $weightSemanticTags = null,
        public ?array      $projectsAliases = null,
        public ?Collection $tagFilters = null,
        public ?array      $documentsAliases = null,
        public ?array      $chunksIds = null,
    ) {
        $this->tagFilters ??= collect();
    }
}
