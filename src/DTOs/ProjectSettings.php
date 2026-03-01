<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

class ProjectSettings
{
    public function __construct(
        public ?string $post_processor_agent_instructions = null,
        public ?string $search_agent_instructions = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            post_processor_agent_instructions: $data['post_processor_agent_instructions'] ?? null,
            search_agent_instructions: $data['search_agent_instructions'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'post_processor_agent_instructions' => $this->post_processor_agent_instructions,
            'search_agent_instructions' => $this->search_agent_instructions,
        ];
    }
}
