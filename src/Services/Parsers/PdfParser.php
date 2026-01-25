<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use Dolphin\SimpleStorage\SimpleStorageClient;
use SimoneBianco\DolphinParser\DolphinParserClient;
use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Exceptions\RemoteStorageFileMissingException;
use SimoneBianco\LaravelRagChunks\Services\FileService;
use SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts\DocumentParserInterface;
use Throwable;

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
     * @throws ClientException
     */
    public function dispatchParsing(string $absolutePath): array
    {
        try {
            $response = $this->dolphinParser->parseFileAsync($absolutePath);
        } catch (Throwable $exception) {
            throw ClientException::makeFromException($exception);
        }

        if (!$response->jobId || $response->isFailed()) {
            throw new ClientException(
                message: "Parsing dispatch failed: $response->error",
                response: $response->toArray(),
            );
        }

        return ['job_id' => $response->jobId];
    }

    /**
     * @param array $data
     * @return ParserStatus
     * @throws ClientException
     */
    public function pollParsing(array $data): ParserStatus
    {
        try {
            $response = $this->dolphinParser->status($data['job_id']);
        } catch (Throwable $exception) {
            throw ClientException::makeFromException($exception);
        }

        if ($response->isFailed()) {
            throw new ClientException(
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
     * @throws ClientException
     */
    public function getParsingResult(array $data): string
    {
        try {
            if (!$this->simpleStorage->exists($data['job_id'])) {
                throw new RemoteStorageFileMissingException();
            }

            $directoryPath = $this->fileService->generateDirPath();
            $filePath = $this->fileService->generateFilePath($directoryPath, '.zip');
            $this->simpleStorage->downloadTo($data['job_id'], $this->fileService->getAbsolutePath($filePath));
        } catch (RemoteStorageFileMissingException $e) {
            throw $e;
        } catch (Throwable $exception) {
            throw ClientException::makeFromException($exception);
        }

       return $this->fileService->extractAndDelete($filePath, $directoryPath);
    }
}
