<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts;

use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;

interface DocumentParserInterface
{
    /**
     * @param string $absolutePath
     * @return array
     * @throws ClientException
     */
    public function dispatchParsing(string $absolutePath): array;
    public function pollParsing(array $data): ParserStatus;
}
