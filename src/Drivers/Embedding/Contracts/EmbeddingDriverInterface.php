<?php

namespace SimoneBianco\LaravelRagChunks\Drivers\Embedding\Contracts;

interface EmbeddingDriverInterface
{
    /**
     * @param string $text
     * @return array
     */
    public function embed(string $text): array;
}
