<?php

namespace SimoneBianco\LaravelRagChunks\Services;

class HashService
{
    public function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}
