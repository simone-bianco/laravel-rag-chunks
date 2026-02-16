<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\Pdf\PollingContextDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\Pdf\PostProcessingContextDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\Pdf\RefiningContextDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\RefinedItemDTO;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidEmbeddingDriverException;
use SimoneBianco\LaravelRagChunks\Exceptions\PostProcessingException;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\DocumentService;
use SimoneBianco\LaravelRagChunks\Services\PostProcessors\PostProcessor;
use SimoneBianco\LaravelRagChunks\Services\StreamService;
use SimoneBianco\SimpleStorageClient\Exceptions\ConnectionFailedException;
use SimoneBianco\SimpleStorageClient\Exceptions\SimpleStorageException;
use SimoneBianco\SimpleStorageClient\Exceptions\UnauthorizedException;
use SimoneBianco\SimpleStorageClient\SimpleStorageClient;
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
        protected StreamService               $streamService,
        protected DocumentService             $documentService,
        protected PostProcessor               $postProcessor
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
     * @param bool $deleteLocal
     * @param bool $deleteRemote
     * @return array
     * @throws ClientException
     * @throws InvalidFileException
     */
    public function saveParsingResult(array $data, bool $deleteLocal = true, bool $deleteRemote = false): array
    {
        $jobId = PollingContextDTO::fromArray($data)->jobId;

        try {
            if (!$jobId || !$this->simpleStorage->exists($jobId)) {
                throw new \InvalidArgumentException("Job '$jobId' not found");
            }

            $path = "{$this->fileService->generateTempDirPath($jobId)}/$jobId";
            $targetAbsolutePath = $this->fileService->getAbsolutePath($path);
            $this->simpleStorage->downloadTo($jobId, $targetAbsolutePath, !$deleteRemote);

            return new RefiningContextDTO($this->extractParsingResult($path, $deleteLocal))->toArray();
        } catch (SimpleStorageException|ConnectionFailedException|UnauthorizedException $exception) {
            throw ClientException::makeFromException($exception);
        }
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
            $this->fileService->delete($zipRelativePath);
        }

        return $dirRelativePath;
    }

    /**
     * @param array $data
     * @return array
     * @throws InvalidFileException
     */
    public function refineOutputJson(array $data): array
    {
        try {
            $dirRelativePath = RefiningContextDTO::fromArray($data)->relativeDirPath;

            /** @var string $outputJsonRelativePath */
            $outputJsonRelativePath = collect($this->fileService->files($dirRelativePath))->first(function ($file) {
                return basename($file) === 'output.json';
            });

            if (!$this->fileService->exists($outputJsonRelativePath)) {
                throw new InvalidFileException("$outputJsonRelativePath does not exist");
            }

            $writeRelativePath = "$dirRelativePath/refined_output.jsonl";
            $this->fileService->createDirectoryIfNotExists($dirRelativePath);
            $stream = $this->fileService->writeStream($writeRelativePath, 'w');

            $jsonAbsolutePath = $this->fileService->getAbsolutePath($outputJsonRelativePath);
            foreach ($this->dolphinOutputChunker->chunkOutputJson($jsonAbsolutePath) as $chunks) {
                /** @var RefinedItemDTO $row */
                foreach ($chunks as $row) {
                    $this->fileService->writeOnStream(
                        $stream,
                        json_encode($row->toArray(), JSON_UNESCAPED_UNICODE) . "\n"
                    );
                }
            }

            $this->fileService->closeStreams($stream);
            $this->fileService->delete($outputJsonRelativePath);

            return new PostProcessingContextDTO($dirRelativePath, $writeRelativePath)->toArray();
        } catch (InvalidArgumentException $exception) {
            throw new InvalidFileException(message: $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @param string $documentContext
     * @param array $data
     * @param int $batchSize
     * @return array
     * @throws InvalidFileException
     * @throws PostProcessingException
     * @throws InvalidEmbeddingDriverException
     */
    public function postProcess(?string $documentContext, array $data, int $batchSize = 20): array
    {
        $postProcessingData = PostProcessingContextDTO::fromArray($data);

        if (!$this->fileService->exists($postProcessingData->relativeRefinedPath)) {
            throw new InvalidFileException("$postProcessingData->relativeRefinedPath does not exist");
        }

        $relativePostProcessedOutputPath = "$postProcessingData->relativeDirPath/post_processed.jsonl";
        if (!$this->fileService->exists($relativePostProcessedOutputPath)) {
            $this->fileService->put($relativePostProcessedOutputPath, '');
        }

        $this->postProcessor->postProcess(
            $postProcessingData->relativeRefinedPath,
            $relativePostProcessedOutputPath,
            $documentContext,
            $batchSize
        );

        return new PostProcessingContextDTO(
            $postProcessingData->relativeDirPath,
            $postProcessingData->relativeRefinedPath,
            $relativePostProcessedOutputPath
        )->toArray();
    }

    /**
     * @param Document $document
     * @param array $data
     * @return Document
     * @throws FileNotFoundException
     * @throws Throwable
     */
    public function saveDocument(Document $document, array $data): Document
    {
        $postProcessingData = PostProcessingContextDTO::fromArray($data);

        return $this->documentService->regeneratePostProcessedChunks(
            $document,
            $postProcessingData->relativePostProcessedPath
        );
    }
}
