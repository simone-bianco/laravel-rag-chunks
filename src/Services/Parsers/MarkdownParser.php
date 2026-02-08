<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use InvalidArgumentException;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\Markdown\RefiningContextDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\Pdf\PostProcessingContextDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\RefinedItemDTO;
use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\Chunkers\MarkdownChunkerService;
use SimoneBianco\LaravelRagChunks\Services\DocumentService;
use SimoneBianco\LaravelRagChunks\Services\FileService;
use SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts\DocumentParserInterface;
use SimoneBianco\LaravelRagChunks\Services\PostProcessors\PostProcessor;
use Throwable;

class MarkdownParser implements DocumentParserInterface
{
    public function __construct(
        protected FileService $fileService,
        protected MarkdownChunkerService $chunkerService,
        protected PostProcessor $postProcessor,
        protected DocumentService $documentService
    ) {}

    public function needsPolling(): bool
    {
        return false;
    }

    public function saveParsingResult(array $data, bool $deleteLocal = true, bool $deleteRemote = false): array
    {
        throw new InvalidArgumentException("Markdown parser doesn't need to save parsing result");
    }

    /**
     * @param string $absolutePath
     * @return array
     * @throws FileNotFoundException
     * @throws InvalidFileException
     */
    public function dispatchParsing(string $absolutePath): array
    {
        if (!file_exists($absolutePath)) {
            throw new FileNotFoundException("File not found at $absolutePath");
        }

        if (pathinfo($absolutePath, PATHINFO_EXTENSION) !== 'md') {
            throw new InvalidFileException("File at '$absolutePath' is not an md file");
        }

        $relativeDirPath = $this->fileService->generateDirPath();
        $this->fileService->createDirectoryIfNotExists($relativeDirPath);

        $filename = pathinfo($absolutePath, PATHINFO_BASENAME);
        $relativeFilePath = "$relativeDirPath/$filename";
        $this->fileService->moveFileA2R($absolutePath, $relativeFilePath);

        return RefiningContextDTO::fromArray([
            'relative_dir_path' => $relativeDirPath,
            'relative_file_path' => $relativeFilePath,
        ])->toArray();
    }

    public function pollParsing(array $data): ParserStatus
    {
        throw new InvalidArgumentException("Markdown parser doesn't need polling");
    }

    public function refineOutputJson(array $data): array
    {
        $context = RefiningContextDTO::fromArray($data);

        $writeRelativePath = "$context->relativeDirPath/refined_output.jsonl";
        $writeStream = $this->fileService->writeStream($writeRelativePath, 'w');
        $absolutePath = $this->fileService->getAbsolutePath($context->relativeFilePath);
        /** @var array<RefinedItemDTO> $rawItems */
        foreach ($this->chunkerService->chunkMarkdown($absolutePath) as $rawItems) {
            foreach ($rawItems as $item) {
                $this->fileService->writeOnStream(
                    $writeStream,
                    json_encode($item->toArray(), JSON_UNESCAPED_UNICODE) . "\n"
                );
            }
        }

        $this->fileService->closeStreams($writeStream);

        return new PostProcessingContextDTO(
            $context->relativeDirPath,
            $writeRelativePath
        )->toArray();
    }

    public function postProcess(string $documentContext, array $data, int $batchSize = 20): array
    {
        $context = PostProcessingContextDTO::fromArray($data);

        if (!$this->fileService->exists($context->relativeRefinedPath)) {
            throw new FileNotFoundException("File not found at {$context->relativeRefinedPath}");
        }

        $writeRelativePath = "$context->relativeDirPath/post_processed_output.jsonl";
        if (!$this->fileService->exists($writeRelativePath)) {
            $this->fileService->put($writeRelativePath, '');
        }

        $this->postProcessor->postProcess($context->relativeRefinedPath, $writeRelativePath, $batchSize);

        return new PostProcessingContextDTO(
            $context->relativeDirPath,
            $context->relativeRefinedPath,
            $writeRelativePath
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
