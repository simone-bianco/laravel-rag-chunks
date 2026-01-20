<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts;

use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Models\Document;

interface DocumentParserInterface
{

    public function dispatchParsing(string $absolutePath): array;
    public function pollParsing(array $data): ParserStatus;
}
