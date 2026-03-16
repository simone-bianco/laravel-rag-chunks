<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\ParsingContextDTO;
use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\DocumentService;
use SimoneBianco\LaravelRagChunks\Services\FileService;
use SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts\DocumentParserInterface;
use SimoneBianco\LaravelRagChunks\Services\PostProcessors\PostProcessor;

abstract class AbstractLocalFileParser implements DocumentParserInterface
{
    public function __construct(
        protected FileService $fileService,
        protected PostProcessor $postProcessor,
        protected DocumentService $documentService,
    ) {}

    /**
     * Returns the expected file extension this parser handles (e.g. 'json', 'md', 'docx').
     *
     * @return string
     */
    abstract protected function getExpectedExtension(): string;

    /**
     * Creates the initial parser-specific context DTO after the file is moved into temp storage.
     *
     * @param string $relativeDirPath
     * @param string $relativeFilePath
     * @return ParsingContextDTO
     */
    abstract protected function makeInitialContext(string $relativeDirPath, string $relativeFilePath): ParsingContextDTO;

    /**
     * Reconstructs the appropriate DTO from the raw process context array.
     *
     * @param array<string, mixed> $data
     * @return ParsingContextDTO
     */
    abstract public function contextFromArray(array $data): ParsingContextDTO;

    /**
     * Performs the refinement step: reads the parsed file and writes a refined_output.jsonl.
     *
     * @param ParsingContextDTO $context
     * @return ParsingContextDTO
     * @throws InvalidFileException
     */
    abstract public function refineOutputJson(ParsingContextDTO $context): ParsingContextDTO;

    /**
     * Local file parsers never need polling against an external service.
     *
     * @return bool
     */
    final public function needsPolling(): bool
    {
        return false;
    }

    /**
     * Not applicable to local file parsers — throws immediately.
     *
     * @param ParsingContextDTO $context
     * @return ParserStatus
     * @throws \InvalidArgumentException
     */
    final public function pollParsing(ParsingContextDTO $context): ParserStatus
    {
        throw new \InvalidArgumentException(get_class($this) . " doesn't need polling");
    }

    /**
     * Not applicable to local file parsers — throws immediately.
     *
     * @param ParsingContextDTO $context
     * @param bool $deleteLocal
     * @param bool $deleteRemote
     * @return ParsingContextDTO
     * @throws \InvalidArgumentException
     */
    final public function saveParsingResult(ParsingContextDTO $context, bool $deleteLocal = true, bool $deleteRemote = false): ParsingContextDTO
    {
        throw new \InvalidArgumentException(get_class($this) . " doesn't need saveParsingResult");
    }

    /**
     * Moves the file into a temporary directory and returns the initial context DTO.
     *
     * @param string $absolutePath Absolute path to the source file.
     * @return ParsingContextDTO
     * @throws FileNotFoundException  If the file does not exist at the given path.
     * @throws InvalidFileException   If the file extension does not match the expected one.
     */
    final public function dispatchParsing(string $absolutePath): ParsingContextDTO
    {
        if (!file_exists($absolutePath)) {
            throw new FileNotFoundException("File not found at $absolutePath");
        }

        if (pathinfo($absolutePath, PATHINFO_EXTENSION) !== $this->getExpectedExtension()) {
            throw new InvalidFileException(
                "File at '$absolutePath' is not a .{$this->getExpectedExtension()} file"
            );
        }

        $relativeDirPath = $this->fileService->generateTempDirPath();
        $this->fileService->createDirectoryIfNotExists($relativeDirPath);

        $filename = pathinfo($absolutePath, PATHINFO_BASENAME);
        $relativeFilePath = "$relativeDirPath/$filename";
        $this->fileService->moveFileA2R($absolutePath, $relativeFilePath);

        return $this->makeInitialContext($relativeDirPath, $relativeFilePath);
    }

    /**
     * Runs the post-processing step: enriches the refined JSONL and writes post_processed_output.jsonl.
     *
     * @param string|null $documentContext Optional document-level context to pass to the post-processor.
     * @param ParsingContextDTO $context   The context holding relativeRefinedPath and relativeDirPath.
     * @param int $batchSize               Number of items to process per batch (default 8).
     * @return ParsingContextDTO           Updated context with relativePostProcessedPath set.
     * @throws InvalidFileException        If the refined file does not exist.
     */
    public function postProcess(
        ?string $documentContext,
        ParsingContextDTO $context,
        int $batchSize = 8,
        array $agentOptions = [],
        int $startFromInputLine = 0,
        ?callable $onBatchComplete = null
    ): ParsingContextDTO {
        if (!$this->fileService->exists($context->relativeRefinedPath)) {
            throw new InvalidFileException("File not found at {$context->relativeRefinedPath}");
        }

        $writeRelativePath = "$context->relativeDirPath/post_processed_output.jsonl";

        // Partenza fresca: crea/tronca il file di output
        // Resume (startFromInputLine > 0): preserva il file esistente per appendere
        if ($startFromInputLine === 0 || !$this->fileService->exists($writeRelativePath)) {
            $this->fileService->put($writeRelativePath, '');
        }

        $this->postProcessor->postProcess(
            $context->relativeRefinedPath,
            $writeRelativePath,
            $documentContext,
            $batchSize,
            $agentOptions,
            $startFromInputLine,
            $onBatchComplete
        );

        $context->relativePostProcessedPath = $writeRelativePath;

        return $context;
    }

    /**
     * Persists the post-processed chunks to the document model.
     *
     * @param Document $document          The document to update with regenerated chunks.
     * @param ParsingContextDTO $context  The context holding relativePostProcessedPath.
     * @return Document                   The updated document.
     */
    public function saveDocument(Document $document, ParsingContextDTO $context, array $options = []): Document
    {
        return $this->documentService->regeneratePostProcessedChunks(
            $document,
            $context->relativePostProcessedPath,
            $options
        );
    }
}
