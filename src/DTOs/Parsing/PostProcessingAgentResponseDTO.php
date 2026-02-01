<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parsing;

class PostProcessingAgentResponseDTO
{
    public function __construct(
        public array $tags,
        public array $questions,
    ) {}

    public function getImplodedTags(): string
    {
        return implode(',', $this->tags);
    }

    public function getImplodedQuestions(): string
    {
        return implode(',', $this->questions);
    }
}
