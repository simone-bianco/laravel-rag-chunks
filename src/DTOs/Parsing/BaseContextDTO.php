<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parsing;

use Illuminate\Contracts\Support\Arrayable;

abstract class BaseContextDTO implements Arrayable
{
    abstract public function toArray(): array;

    abstract public static function fromArray(array $data): static;
}
