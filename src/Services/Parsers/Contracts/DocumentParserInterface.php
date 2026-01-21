<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts;

use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Exceptions\ParsingDispatchException;

interface DocumentParserInterface
{
    /**
     * @param string $absolutePath
     * @return array
     * @throws ParsingDispatchException
     */
    public function dispatchParsing(string $absolutePath): array;
    public function pollParsing(array $data): ParserStatus;
}
