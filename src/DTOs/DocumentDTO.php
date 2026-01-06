<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

readonly class DocumentDTO
{
    public function __construct(
        public string $text,
        public ?string $alias = null,
        public ?string $name = null,
        public ?string $hash = null,
        public array $metadata = [],
    ) {}
}
