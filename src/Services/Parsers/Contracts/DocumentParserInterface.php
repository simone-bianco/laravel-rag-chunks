<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts;

use Generator;
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
    public function saveParsingResult(string $jobId): array;
    public function chunkDocument(
        array $data,
        int $maxChunkSize = 500,
        int $generatorChunkSize = 50,
        array &$errors = []
    ): Generator;
}
