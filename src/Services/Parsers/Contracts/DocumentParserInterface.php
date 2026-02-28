<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\ParsingContextDTO;
use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidEmbeddingDriverException;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;
use SimoneBianco\LaravelRagChunks\Exceptions\PostProcessingException;
use SimoneBianco\LaravelRagChunks\Models\Document;
use Throwable;

interface DocumentParserInterface
{
    public function needsPolling(): bool;

    /**
     * Reconstruct the appropriate DTO from the raw process context array.
     */
    public function contextFromArray(array $data): ParsingContextDTO;

    /**
     * @throws ClientException
     */
    public function dispatchParsing(string $absolutePath): ParsingContextDTO;

    /**
     * @throws ClientException
     */
    public function pollParsing(ParsingContextDTO $context): ParserStatus;

    /**
     * @throws ClientException
     * @throws InvalidFileException
     */
    public function saveParsingResult(ParsingContextDTO $context, bool $deleteLocal = true, bool $deleteRemote = false): ParsingContextDTO;

    /**
     * @throws InvalidFileException
     */
    public function refineOutputJson(ParsingContextDTO $context): ParsingContextDTO;

    /**
     * @throws InvalidFileException
     * @throws PostProcessingException
     * @throws InvalidEmbeddingDriverException
     */
    public function postProcess(?string $documentContext, ParsingContextDTO $context, int $batchSize = 20, array $agentOptions = []): ParsingContextDTO;

    /**
     * @throws FileNotFoundException
     * @throws Throwable
     */
    public function saveDocument(Document $document, ParsingContextDTO $context): Document;
}
