<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parser;

use Illuminate\Contracts\Support\Arrayable;

abstract class BaseContextDTO implements Arrayable
{
    abstract public function toArray(): array;

    abstract public static function fromArray(array $data): static;
}
