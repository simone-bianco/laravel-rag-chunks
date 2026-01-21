<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use Dolphin\SimpleStorage\SimpleStorageClient;
use Illuminate\Http\Client\ConnectionException;
use SimoneBianco\DolphinParser\DolphinParserClient;
use SimoneBianco\DolphinParser\Exceptions\ApiRequestException;
use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;
use SimoneBianco\LaravelRagChunks\Exceptions\ParsingDispatchException;
use SimoneBianco\LaravelRagChunks\Exceptions\RemoteStorageFileMissingException;
use SimoneBianco\LaravelRagChunks\Services\FileService;
use SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts\DocumentParserInterface;

class PdfParser implements DocumentParserInterface
{
    public function __construct(
        protected FileService $fileService,
        protected DolphinParserClient $dolphinParser,
        protected SimpleStorageClient $simpleStorage
    ) {}

    /**
     * @param string $absolutePath
     * @return array
     * @throws ParsingDispatchException
     * @throws ConnectionException
     * @throws ApiRequestException
     */
    public function dispatchParsing(string $absolutePath): array
    {
        $response = $this->dolphinParser->parseFileAsync($absolutePath);

        if (!$response->jobId || $response->isFailed()) {
            throw new ParsingDispatchException(
                message: "Parsing dispatch failed: $response->error",
                response: $response->toArray(),
            );
        }

        return ['job_id' => $response->jobId];
    }

    /**
     * @throws ParsingDispatchException
     */
    public function pollParsing(array $data): ParserStatus
    {
        $response = $this->dolphinParser->status($data['job_id']);

        if ($response->isFailed()) {
            throw new ParsingDispatchException(
                message: "Parsing processing failed: $response->error",
                response: $response->toArray(),
            );
        }

        if ($response->isSuccess()) {
            return ParserStatus::COMPLETED;
        }

        return ParserStatus::PROCESSING;
    }

    /**
     * @param array $data
     * @return string
     * @throws InvalidFileException
     * @throws RemoteStorageFileMissingException
     */
    public function getParsingResult(array $data): string
    {
        if (!$this->simpleStorage->exists($data['job_id'])) {
            throw new RemoteStorageFileMissingException();
        }

        $directoryPath = $this->fileService->generateDirPath();
        $filePath = $this->fileService->generateFilePath($directoryPath, '.zip');
        $this->simpleStorage->downloadTo($data['job_id'], $this->fileService->getAbsolutePath($filePath));

       return $this->fileService->extractAndDelete($filePath, $directoryPath);
    }
}
