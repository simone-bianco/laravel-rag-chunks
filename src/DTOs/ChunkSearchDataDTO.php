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
        public float       $weightContent = 1.0,
        public float       $weightQuestions = 0.7,
        public float       $weightSemanticTags = 0.7,
        public ?array      $projectsAliases = null,
        public ?Collection $tagFilters = null,
        public ?array      $documentsAliases = null,
        public ?array      $chunksIds = null,
        public ?bool       $hasImage = null,
        public bool        $includeEmbeddings = false,
        public bool        $includePageUrls = false,
        // chunk-level classic tag filter: typeAlias => tagIds[]
        public ?array      $chunkTagGroups = null,
    ) {
        $this->tagFilters ??= collect();
    }

    public static function fromArray(array $data): self
    {
        return new self(
            page: $data['page'] ?? 1,
            perPage: $data['perPage'] ?? 100,
            keywordsSearch: $data['keywordsSearch'] ?? null,
            textSearch: $data['textSearch'] ?? null,
            questionsSearch: $data['questionsSearch'] ?? null,
            semanticTagsSearch: $data['semanticTagsSearch'] ?? null,
            weightContent: isset($data['weightContent']) ? (float) $data['weightContent'] : 1.0,
            weightQuestions: isset($data['weightQuestions']) ? (float) $data['weightQuestions'] : 0.7,
            weightSemanticTags: isset($data['weightSemanticTags']) ? (float) $data['weightSemanticTags'] : 0.7,
            projectsAliases: $data['projectsAliases'] ?? null,
            tagFilters: isset($data['tagFilters']) ? collect($data['tagFilters']) : null,
            documentsAliases: $data['documentsAliases'] ?? null,
            chunksIds: $data['chunksIds'] ?? null,
            hasImage: self::resolveHasImage($data['hasImage'] ?? null),
            chunkTagGroups: $data['chunkTagGroups'] ?? null,
        );
    }

    private static function resolveHasImage(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if ($value === 'with') {
            return true;
        }
        if ($value === 'without') {
            return false;
        }
        if ($value === 'mixed') {
            return null;
        }
        // Legacy boolean support
        return (bool) $value ?: null;
    }

    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'perPage' => $this->perPage,
            'keywordsSearch' => $this->keywordsSearch,
            'textSearch' => $this->textSearch,
            'questionsSearch' => $this->questionsSearch,
            'semanticTagsSearch' => $this->semanticTagsSearch,
            'weightContent' => $this->weightContent,
            'weightQuestions' => $this->weightQuestions,
            'weightSemanticTags' => $this->weightSemanticTags,
            'projectsAliases' => $this->projectsAliases,
            'tagFilters' => $this->tagFilters?->toArray(),
            'documentsAliases' => $this->documentsAliases,
            'chunksIds' => $this->chunksIds,
            'hasImage' => $this->hasImage,
        ];
    }
}
