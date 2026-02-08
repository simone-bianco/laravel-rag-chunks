<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidEmbeddingDriverException;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;
use SimoneBianco\LaravelRagChunks\Exceptions\PostProcessingException;
use SimoneBianco\LaravelRagChunks\Models\Document;
use Throwable;

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
     * @param bool $deleteLocal
     * @param bool $deleteRemote
     * @return array
     * @throws ClientException
     */
    public function saveParsingResult(array $data, bool $deleteLocal = true, bool $deleteRemote = false): array;

    /**
     * @param array $data
     * @return array
     * @throws InvalidFileException
     */
    public function refineOutputJson(array $data): array;

    /**
     * @param string $documentContext
     * @param array $data
     * @param int $batchSize
     * @return array
     * @throws InvalidFileException
     * @throws PostProcessingException
     * @throws InvalidEmbeddingDriverException
     */
    public function postProcess(string $documentContext, array $data, int $batchSize = 20): array;

    /**
     * @param Document $document
     * @param array $data
     * @return Document
     * @throws FileNotFoundException
     * @throws Throwable
     */
    public function saveDocument(Document $document, array $data): Document;
}
