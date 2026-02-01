<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts;

use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;

interface DocumentParserInterface
{
    /**
     * @return bool
     */
    public function needsPolling(): bool;

    /**
     * @param string $absolutePath
     * @return array
     * @throws ClientException
     */
    public function dispatchParsing(string $absolutePath): array;

    /**
     * @param array $data
     * @return ParserStatus
     * @throws ClientException
     */
    public function pollParsing(array $data): ParserStatus;

    /**
     * @param array $data
     * @return array
     * @throws ClientException
     */
    public function saveParsingResult(array $data): array;

    /**
     * @param array $data
     * @return array
     * @throws InvalidFileException
     */
    public function refineOutputJson(array $data): array;
}
