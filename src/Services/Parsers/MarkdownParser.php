<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\Markdown\RefiningContextDTO;
use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;
use SimoneBianco\LaravelRagChunks\Services\Chunkers\MarkdownChunkerService;
use SimoneBianco\LaravelRagChunks\Services\FileService;
use SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts\DocumentParserInterface;

class MarkdownParser implements DocumentParserInterface
{
    public function __construct(
        protected FileService $fileService,
        protected MarkdownChunkerService $chunkerService
    ) {}

    public function needsPolling(): bool
    {
        return false;
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
        $this->fileService->moveFile($absolutePath, "$relativeDirPath/$filename");

        return RefiningContextDTO::fromArray([
            'relative_dir_path' => $relativeDirPath,
        ])->toArray();
    }

    public function pollParsing(array $data): ParserStatus
    {
        return ParserStatus::COMPLETED;
    }

    public function saveParsingResult(array $data, bool $deleteLocal = true, bool $deleteRemote = false): array
    {
        // TODO: Implement saveParsingResult() method.
    }

    public function refineOutputJson(array $data): array
    {
        // TODO: Implement refineOutputJson() method.
    }
}
