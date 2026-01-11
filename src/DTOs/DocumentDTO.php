<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

use Illuminate\Support\Collection;

class DocumentDTO
{
    /**
     * @param string $text
     * @param string|null $alias
     * @param string|null $name
     * @param string|null $description
     * @param string|null $hash
     * @param Collection<array<string>>|null $tagsByType
     * @param array $typelessTags
     * @param array $metadata
     */
    public function __construct(
        public string $text,
        public string $project_id, // Mandatory
        public ?string $alias = null,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $hash = null,
        public array $tags = [], // type => [tags]
        public array $metadata = [],
    ) {
    }
}
