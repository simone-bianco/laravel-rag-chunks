<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use Dolphin\SimpleStorage\Facades\SimpleStorage;
use SimoneBianco\DolphinParser\Facades\DolphinParser;
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
    ) {}

    /**
     * @param string $absolutePath
     * @return array
     * @throws ParsingDispatchException
     */
    public function dispatchParsing(string $absolutePath): array
    {
        $response = DolphinParser::parseFileAsync($absolutePath);

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
        $response = DolphinParser::status($data['job_id']);

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
        if (!SimpleStorage::exists($data['job_id'])) {
            throw new RemoteStorageFileMissingException();
        }

        $directoryPath = $this->fileService->generateDirPath();
        $filePath = $this->fileService->generateFilePath($directoryPath, '.zip');
        SimpleStorage::downloadTo($data['job_id'], $this->fileService->getAbsolutePath($filePath));

       return $this->fileService->extractAndDelete($filePath, $directoryPath);
    }
}
