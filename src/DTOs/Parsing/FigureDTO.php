<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parsing;

use Illuminate\Contracts\Support\Arrayable;

class FigureDTO implements Arrayable
{
    public function __construct(
        public string $path,
        public string $description
    ) {}

    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'description' => $this->description
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(...$data);
    }
}
