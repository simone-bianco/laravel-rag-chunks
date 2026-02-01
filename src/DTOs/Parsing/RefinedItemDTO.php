<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parsing;

use Illuminate\Contracts\Support\Arrayable;

class RefinedItemDTO implements Arrayable
{
    public function __construct(
        public string $text,
        public ?string $figurePath = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'text' => $this->text,
            'figure_path' => $this->figurePath,
        ]);
    }

    public static function fromArray(array $data): static
    {
        return new static(
            text: $data['text'],
            figurePath: $data['figure_path'] ?? null,
        );
    }
}
