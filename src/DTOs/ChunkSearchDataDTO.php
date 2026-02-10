<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

use Illuminate\Support\Collection;

class ChunkSearchDataDTO
{
    public function __construct(
        public int         $page = 1,
        public int         $perPage = 100,
        // keywords are key-insensitive and are used to filter chunks (they must contain those words)
        public ?array      $keywordsSearch = null,
        public ?string     $textSearch = null,
        public ?string     $questionsSearch = null,
        public ?string     $semanticTagsSearch = null,
        public float       $weightContent = 1,
        public float       $weightQuestions = 1,
        public float       $weightSemanticTags = 1,
        public ?array      $projectsAliases = null,
        public ?Collection $tagFilters = null,
        public ?array      $documentsAliases = null,
        public ?array      $chunksIds = null,
    ) {
        $this->tagFilters ??= collect();
    }
}
