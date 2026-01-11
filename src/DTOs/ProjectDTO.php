<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

class ProjectDTO
{
    public function __construct(
        public string $name,
        public string $alias,
        public string $tagsBlueprintAlias,
        public array $settings = []
    ) {}
}
