<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parsing;

use Illuminate\Contracts\Support\Arrayable;

abstract class ParsingContextDTO implements Arrayable
{
    public function __construct(
        public bool $computeEmbeddings = true,
        public bool $postProcess = true,
    ) {}

    abstract public function toArray(): array;

    abstract public static function fromArray(array $data): static;
}
