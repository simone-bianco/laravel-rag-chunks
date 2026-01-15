<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

class DocumentDTO
{
    /**
     * @param string $filePath
     * @param string $project_id
     * @param string $name
     * @param string|null $alias
     * @param string|null $description
     * @param string|null $hash
     * @param array $tags
     * @param array $metadata
     */
    public function __construct(
        public string $filePath,
        public string $project_id,
        public string $name,
        public ?string $alias = null,
        public ?string $description = null,
        public ?string $hash = null,
        public array $tags = [],
        public array $metadata = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'filePath' => $this->filePath,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'alias' => $this->alias,
            'description' => $this->description,
            'hash' => $this->hash,
            'tags' => $this->tags,
            'metadata' => $this->metadata,
        ];
    }
}
