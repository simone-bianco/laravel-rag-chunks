<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

use SimoneBianco\LaravelRagChunks\Enums\TagFilterMode;

readonly class TagFilterDTO
{
    public function __construct(
        public string $tag,
        public ?string $type = null,
        public TagFilterMode $rule_filter = TagFilterMode::ANY,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            tag: $data['tag'],
            type: $data['type'] ?? null,
            rule_filter: isset($data['rule_filter']) 
                ? TagFilterMode::from($data['rule_filter']) 
                : TagFilterMode::ANY,
        );
    }
}
