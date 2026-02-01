<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use SimoneBianco\LaravelRagChunks\DTOs\Parser\Pdf\PollingContextDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parser\Pdf\ProcessingContextDTO;
use SimoneBianco\SimpleStorageClient\Exceptions\ConnectionFailedException;
use SimoneBianco\SimpleStorageClient\Exceptions\SimpleStorageException;
use SimoneBianco\SimpleStorageClient\Exceptions\UnauthorizedException;
use SimoneBianco\SimpleStorageClient\SimpleStorageClient;
use Illuminate\Contracts\Filesystem\Filesystem;
use JsonMachine\Exception\InvalidArgumentException;
use SimoneBianco\DolphinParser\DolphinParserClient;
use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;
use SimoneBianco\LaravelRagChunks\Services\Chunkers\DolphinOutputChunkerService;
use SimoneBianco\LaravelRagChunks\Services\FileService;
use SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts\DocumentParserInterface;
use Throwable;

class PdfParser implements DocumentParserInterface
{
    public function __construct(
        protected FileService                 $fileService,
        protected DolphinParserClient         $dolphinParser,
        protected DolphinOutputChunkerService $dolphinOutputChunker,
        protected SimpleStorageClient         $simpleStorage,
    ) {}

    protected function getRelativeTempPath(): string
    {
        return 'temp';
    }

    public function needsPolling(): bool
    {
        return true;
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

        return new PollingContextDTO($response->jobId)->toArray();
    }

    /**
     * @param array $data
     * @return ParserStatus
     * @throws ClientException
     */
    public function pollParsing(array $data): ParserStatus
    {
        try {
            $context = PollingContextDTO::fromArray($data);

            $response = $this->dolphinParser->status($context->jobId);
        } catch (Throwable $exception) {
            throw ClientException::makeFromException($exception);
        }

        if ($response->isFailed()) {
            throw new ClientException(
                "Parsing processing failed: $response->error",
                0,
                null,
                null,
                $response->toArray()
            );
        }

        if ($response->isSuccess()) {
            return ParserStatus::COMPLETED;
        }

        return ParserStatus::PROCESSING;
    }

    /**
     * @param array $data
     * @return array
     * @throws ClientException
     * @throws InvalidFileException
     */
    public function saveParsingResult(array $data): array
    {
        $jobId = PollingContextDTO::fromArray($data)->jobId;

        try {
            if (!$jobId || !$this->simpleStorage->exists($jobId)) {
                throw new \InvalidArgumentException("Job '$jobId' not found");
            }

            $path = sprintf(
                '%s/%s/%s.zip',
                $this->getRelativeTempPath(),
                $jobId,
                $jobId
            );
            $targetAbsolutePath = $this->fileService->getAbsolutePath("$path");
            $this->simpleStorage->downloadTo($jobId, $targetAbsolutePath, true);

            return new ProcessingContextDTO($this->extractParsingResult($path))->toArray();
        } catch (SimpleStorageException|ConnectionFailedException|UnauthorizedException $exception) {
            throw ClientException::makeFromException($exception);
        }
    }

    protected function storage(): ?Filesystem
    {
        return $this->fileService->getStorage();
    }

    /**
     * @param string $zipRelativePath
     * @param bool $deleteLocal
     * @return string Returns the relative path to the directory containing the parsed result
     * @throws InvalidFileException
     */
    public function extractParsingResult(string $zipRelativePath, bool $deleteLocal = true): string
    {
        $dirRelativePath = $this->fileService->extract($zipRelativePath);

        if ($deleteLocal) {
            $this->storage()->delete($zipRelativePath);
        }

        return $dirRelativePath;
    }

    /**
     * @param array $data
     * @param array $errors
     * @return array
     * @throws InvalidFileException
     */
    public function refineOutputJson(array $data, array &$errors = []): array
    {
        try {
            $dirRelativePath = ProcessingContextDTO::fromArray($data)->relativeDirPath;

            /** @var string $outputJsonRelativePath */
            $outputJsonRelativePath = collect($this->storage()->files($dirRelativePath))->first(function ($file) {
                return pathinfo($file, PATHINFO_EXTENSION) === 'json';
            });

            if (!$this->storage()->exists($outputJsonRelativePath)) {
                throw new InvalidFileException("$outputJsonRelativePath does not exist");
            }

            $writeRelativePath = "$dirRelativePath/refined_output.jsonl";
            $writeAbsolutePath = $this->storage()->path($writeRelativePath);

            $directory = dirname($writeAbsolutePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $stream = fopen($writeAbsolutePath, 'w');

            fwrite($stream, '');
            foreach ($this->dolphinOutputChunker->chunkOutputJson($outputJsonRelativePath, $errors) as $chunks) {
                foreach ($chunks as $row) {
                    fwrite($stream, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
                }
            }
            fclose($stream);

            $this->storage()->delete($outputJsonRelativePath);

            return new ProcessingContextDTO($writeRelativePath)->toArray();
        } catch (InvalidArgumentException $exception) {
            throw new InvalidFileException(message: $exception->getMessage(), previous: $exception);
        }
    }
}
