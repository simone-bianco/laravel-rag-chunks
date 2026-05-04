<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

class ChunkSearchDataDTO
{
    public function __construct(
        public int         $page = 1,
        public int         $perPage = 100,
        // case-insensitive lexical substrings used for hard filtering
        public ?array      $keywordsSearch = null,
        // lexical filter mode: OR (any keyword) / AND (all keywords)
        public string      $keywordsSearchMode = 'AND',
        // optional chapter aliases (OR semantics)
        public ?array      $chapters = null,
        public ?string     $textSearch = null,
        public ?string     $questionsSearch = null,
        public ?string     $semanticTagsSearch = null,
        public float       $weightContent = 1.0,
        public float       $weightQuestions = 0.7,
        public float       $weightSemanticTags = 0.7,
        public ?array      $projectsAliases = null,
        public ?array      $tagFilters = null,
        public ?array      $documentsAliases = null,
        /** @var string[]|null  Document name/description keywords (always OR) */
        public ?array      $documentSearch = null,
        public ?array      $chunksIds = null,
        public ?bool       $hasImage = null,
        public bool        $includeEmbeddings = false,
        public bool        $includePageUrls = false,
        // chunk-level classic tag filter: typeAlias => tagIds[]
        public ?array      $chunkTagGroups = null,
    ) {
        $this->tagFilters ??= [];
    }

    public static function fromArray(array $data): self
    {
        $keywordsPayload = $data['keywordsSearch'] ?? null;
        $keywordsSearch = null;
        $keywordsSearchMode = 'AND';

        if (is_array($keywordsPayload)) {
            // New schema: { keywords: string[], mode: 'OR'|'AND' }
            if (array_key_exists('keywords', $keywordsPayload)) {
                $keywordsSearch = is_array($keywordsPayload['keywords'])
                    ? array_values(array_filter(array_map(
                        static fn ($k) => is_string($k) ? trim($k) : null,
                        $keywordsPayload['keywords']
                    )))
                    : null;

                $mode = strtoupper((string) ($keywordsPayload['mode'] ?? 'AND'));
                $keywordsSearchMode = in_array($mode, ['OR', 'AND'], true) ? $mode : 'AND';
            } else {
                // Legacy schema: keywordsSearch: string[]
                $keywordsSearch = array_values(array_filter(array_map(
                    static fn ($k) => is_string($k) ? trim($k) : null,
                    $keywordsPayload
                )));
            }
        }

        $chapters = isset($data['chapters']) && is_array($data['chapters'])
            ? array_values(array_filter(array_map(
                static fn ($chapter) => is_string($chapter) ? trim($chapter) : null,
                $data['chapters']
            )))
            : null;

        return new self(
            page: $data['page'] ?? 1,
            perPage: $data['perPage'] ?? 100,
            keywordsSearch: ! empty($keywordsSearch) ? $keywordsSearch : null,
            keywordsSearchMode: $keywordsSearchMode,
            chapters: ! empty($chapters) ? $chapters : null,
            textSearch: $data['textSearch'] ?? null,
            questionsSearch: $data['questionsSearch'] ?? null,
            semanticTagsSearch: $data['semanticTagsSearch'] ?? null,
            weightContent: isset($data['weightContent']) ? (float) $data['weightContent'] : 1.0,
            weightQuestions: isset($data['weightQuestions']) ? (float) $data['weightQuestions'] : 0.7,
            weightSemanticTags: isset($data['weightSemanticTags']) ? (float) $data['weightSemanticTags'] : 0.7,
            projectsAliases: $data['projectsAliases'] ?? null,
            tagFilters: isset($data['tagFilters']) && is_array($data['tagFilters']) ? $data['tagFilters'] : null,
            documentsAliases: $data['documentsAliases'] ?? null,
            documentSearch: self::parseDocumentSearch($data['documentSearch'] ?? null),
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
            'keywordsSearchMode' => $this->keywordsSearchMode,
            'chapters' => $this->chapters,
            'textSearch' => $this->textSearch,
            'questionsSearch' => $this->questionsSearch,
            'semanticTagsSearch' => $this->semanticTagsSearch,
            'weightContent' => $this->weightContent,
            'weightQuestions' => $this->weightQuestions,
            'weightSemanticTags' => $this->weightSemanticTags,
            'projectsAliases' => $this->projectsAliases,
            'tagFilters' => $this->tagFilters,
            'documentsAliases' => $this->documentsAliases,
            'documentSearch' => $this->documentSearch,
            'chunksIds' => $this->chunksIds,
            'hasImage' => $this->hasImage,
        ];
    }

    /**
     * Parse documentSearch from object form { keywords: string[] } or legacy string[] form.
     * Always uses OR semantics (each keyword matched against name OR description, keywords OR'd together).
     *
     * @return string[]|null
     */
    private static function parseDocumentSearch(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        // New schema: { keywords: string[] }
        if (array_key_exists('keywords', $value)) {
            $keywords = is_array($value['keywords'])
                ? array_values(array_filter(array_map(
                    static fn ($k) => is_string($k) ? trim($k) : null,
                    $value['keywords']
                )))
                : null;

            return ! empty($keywords) ? $keywords : null;
        }

        // Legacy/fallback: plain string[] — accept only string elements
        $keywords = array_values(array_filter(array_map(
            static fn ($k) => is_string($k) ? trim($k) : null,
            $value
        )));

        return ! empty($keywords) ? $keywords : null;
    }
}
