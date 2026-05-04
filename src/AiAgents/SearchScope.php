<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use SimoneBianco\LaravelRagChunks\Enums\SearchScopeType;

readonly class SearchScope
{
    public function __construct(
        public string          $alias,
        public SearchScopeType $type,
    ) {}

    public function getKey(): string
    {
        return $this->alias;
    }
}
