<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

class ProjectDTO
{
    public function __construct(
        public string $name,
        public string $description,
        public string $alias,
        public array $settings = []
    ) {}
}
