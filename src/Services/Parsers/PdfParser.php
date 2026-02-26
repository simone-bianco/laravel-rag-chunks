<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\ParsingContextDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\Pdf\PdfParsingContextDTO;
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

    public function contextFromArray(array $data): ParsingContextDTO
    {
        return PdfParsingContextDTO::fromArray($data);
    }

    /**
     * @throws ClientException
     */
    public function dispatchParsing(string $absolutePath): ParsingContextDTO
    {
        try {
            $response = $this->dolphinParser->parseFileAsync($absolutePath);
        } catch (Throwable $exception) {
            throw ClientException::makeFromException($exception);
        }

        return new PdfParsingContextDTO(jobId: $response->jobId);
    }

    /**
     * @throws ClientException
     */
    public function pollParsing(ParsingContextDTO $context): ParserStatus
    {
        try {
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
     * @throws ClientException
     * @throws InvalidFileException
     */
    public function saveParsingResult(ParsingContextDTO $context, bool $deleteLocal = true, bool $deleteRemote = false): ParsingContextDTO
    {
        $jobId = $context->jobId;

        try {
            if (!$jobId || !$this->simpleStorage->exists($jobId)) {
                throw new \InvalidArgumentException("Job '$jobId' not found");
            }

            $now = now()->timestamp;
            $path = "{$this->fileService->generateTempDirPath($jobId)}/$now-$jobId";
            $targetAbsolutePath = $this->fileService->getAbsolutePath($path);
            $this->simpleStorage->downloadTo($jobId, $targetAbsolutePath, !$deleteRemote);

            $context->relativeDirPath = $this->extractParsingResult($path, $deleteLocal);

            return $context;
        } catch (SimpleStorageException|ConnectionFailedException|UnauthorizedException $exception) {
            throw ClientException::makeFromException($exception);
        }
    }

    /**
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
     * @param PdfParsingContextDTO $context
     * @return PdfParsingContextDTO
     * @throws InvalidFileException
     */
    public function refineOutputJson(ParsingContextDTO $context): ParsingContextDTO
    {
        try {
            $dirRelativePath = $context->relativeDirPath;

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
//            $this->fileService->delete($outputJsonRelativePath);

            $context->relativeRefinedPath = $writeRelativePath;

            return $context;
        } catch (InvalidArgumentException $exception) {
            throw new InvalidFileException(message: $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @throws InvalidEmbeddingDriverException
     * @throws InvalidFileException
     * @throws PostProcessingException
     */
    public function postProcess(?string $documentContext, ParsingContextDTO $context, int $batchSize = 10): ParsingContextDTO
    {
        if (!$this->fileService->exists($context->relativeRefinedPath)) {
            throw new InvalidFileException("$context->relativeRefinedPath does not exist");
        }

        $relativePostProcessedOutputPath = "$context->relativeDirPath/post_processed.jsonl";
        if (!$this->fileService->exists($relativePostProcessedOutputPath)) {
            $this->fileService->put($relativePostProcessedOutputPath, '');
        }

        $this->postProcessor->postProcess(
            $context->relativeRefinedPath,
            $relativePostProcessedOutputPath,
            $documentContext,
            $batchSize
        );

        $context->relativePostProcessedPath = $relativePostProcessedOutputPath;

        return $context;
    }

    /**
     * @throws FileNotFoundException
     * @throws Throwable
     */
    public function saveDocument(Document $document, ParsingContextDTO $context): Document
    {
        $document->enabled = true;
        return $this->documentService->regeneratePostProcessedChunks(
            $document,
            $context->relativePostProcessedPath
        );
    }
}
