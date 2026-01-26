<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use Dolphin\SimpleStorage\Exceptions\SimpleStorageException;
use Dolphin\SimpleStorage\SimpleStorageClient;
use Generator;
use Illuminate\Contracts\Filesystem\Filesystem;
use JsonMachine\Exception\InvalidArgumentException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use SimoneBianco\DolphinParser\DolphinParserClient;
use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Exceptions\RemoteStorageFileMissingException;
use SimoneBianco\LaravelRagChunks\Services\FileService;
use SimoneBianco\LaravelRagChunks\Services\HashService;
use SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts\DocumentParserInterface;
use Throwable;

class PdfParser implements DocumentParserInterface
{
    protected const string JOB_ID = 'job_id';
    protected const string ZIP_ABSOLUTE_PATH = 'zip_absolute_path';
    protected const string ZIP_RELATIVE_PATH = 'zip_relative_path';
    protected const string FILENAME = 'filename';

    public function __construct(
        protected FileService $fileService,
        protected DolphinParserClient $dolphinParser,
        protected SimpleStorageClient $simpleStorage,
        protected HashService $hashService
    ) {}

    protected function getRelativeTempPath(): string
    {
        return 'temp';
    }

    protected function getRandomFilename(string $extension): string
    {
        return $this->fileService->generateFilePath(extension: $extension);
    }

    protected function getAbsolutePath(string $relativePath): string
    {
        return $this->fileService->getAbsolutePath($relativePath);
    }

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

        return [self::JOB_ID => $response->jobId];
    }

    /**
     * @param array $data
     * @return ParserStatus
     * @throws ClientException
     */
    public function pollParsing(array $data): ParserStatus
    {
        try {
            $response = $this->dolphinParser->status($data[self::JOB_ID]);
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
     * @param string $jobId
     * @return array
     * @throws ClientException
     */
    public function saveParsingResult(string $jobId): array
    {
        try {
            $path = $this->getRelativeTempPath();
            $filename = $this->getRandomFilename('zip');
            $targetAbsolutePath = $this->getAbsolutePath("$path/$filename");
            $zipPath = $this->simpleStorage->downloadTo($jobId, $targetAbsolutePath, true);

            return [
                self::ZIP_ABSOLUTE_PATH => $zipPath,
                self::ZIP_RELATIVE_PATH => $this->fileService->getRelativePath($zipPath),
                self::FILENAME => $filename,
            ];
        } catch (SimpleStorageException $exception) {
            throw ClientException::makeFromException($exception);
        }
    }

    protected function storage(): ?Filesystem
    {
        return $this->fileService->getStorage();
    }

    /**
     * @param array $data
     * @param int $maxChunkSize
     * @param int $generatorChunkSize
     * @param array $errors
     * @return Generator
     * @throws InvalidFileException
     */
    public function chunkDocument(
        array $data,
        int $maxChunkSize = 500,
        int $generatorChunkSize = 50,
        array &$errors = []
    ): Generator {
        try {
            $relativePath = $this->fileService->extract($data[self::ZIP_RELATIVE_PATH] ?? null);

            $outputJsonPath = "$relativePath/output.json";
            if (!$this->storage()->exists($outputJsonPath)) {
                throw new InvalidFileException("$outputJsonPath does not exist");
            }

            $streamPages = $this->storage()->readStream($outputJsonPath);

            $pages = Items::fromStream($streamPages, [
                'pointer' => '/pages',
                'decoder' => new ExtJsonDecoder(true)
            ]);

            $accumulator = [];
            $lastChunk = '';
            $lastFigures = [];
            foreach ($pages as $pageIndex => $page) {
                $elements = $page['elements'] ?? [];
                foreach ($elements as $elementIndex => $element) {
                    try {
                        if ($figurePath = $element['figure_path'] ?? null) {
                            $lastFigures[] = $figurePath;
                            continue;
                        }

                        $text = isset($element['text']) ? trim((string) $element['text']) : '';
                        if ($text !== '') {
                            $separator = ($lastChunk === '') ? '' : "\n";

                            if (strlen($lastChunk) + strlen($text) + strlen($separator) < $maxChunkSize) {
                                $lastChunk .= $separator . $text;
                                continue;
                            }

                            if (!$lastChunk) {
                                $lastChunk = $text;
                                continue;
                            }

                            $trimmedText = trim($lastChunk);
                            $accumulator[] = [
                                'text' => $trimmedText,
                                'figures' => $lastFigures,
                                'hash' => $this->hashService->hash($trimmedText)
                            ];

                            if (count($accumulator) >= $generatorChunkSize) {
                                yield $accumulator;
                                $accumulator = [];
                            }

                            $lastChunk = $text;
                            $lastFigures = [];
                        }
                    } catch (Throwable $exception) {
                        $errors[] = [
                            'pageIndex' => $pageIndex,
                            'elementIndex' => $elementIndex,
                            'exception' => $exception->getMessage()
                        ];
                    }
                }
            }

            if (!empty($lastChunk) || !empty($lastFigures)) {
                $trimmedText = trim($lastChunk);
                $accumulator[] = [
                    'text' => $trimmedText,
                    'figures' => $lastFigures,
                    'hash' => $this->hashService->hash($trimmedText)
                ];
            }

            if (!empty($accumulator)) {
                yield $accumulator;
            }
        } catch (InvalidFileException|InvalidArgumentException $exception) {
            throw new InvalidFileException(message: $exception->getMessage(), previous: $exception);
        }
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
            if (!$this->simpleStorage->exists($data[self::JOB_ID])) {
                throw new RemoteStorageFileMissingException();
            }

            $directoryPath = $this->fileService->generateDirPath();
            $filePath = $this->fileService->generateFilePath($directoryPath, '.zip');
            $this->simpleStorage->downloadTo($data[self::JOB_ID], $this->fileService->getAbsolutePath($filePath));
        } catch (RemoteStorageFileMissingException $e) {
            throw $e;
        } catch (Throwable $exception) {
            throw ClientException::makeFromException($exception);
        }

       return $this->fileService->extractAndDelete($filePath, $directoryPath);
    }
}
