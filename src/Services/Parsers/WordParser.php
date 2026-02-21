<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use RuntimeException;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\ParsingContextDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\Word\WordParsingContextDTO;
use SimoneBianco\LaravelRagChunks\Enums\WordParsingStrategy;
use SimoneBianco\LaravelRagChunks\Services\Chunkers\MarkdownChunkerService;
use SimoneBianco\LaravelRagChunks\Services\Chunkers\TxtChunkerService;
use SimoneBianco\LaravelRagChunks\Services\Chunkers\WordChunkerService;
use SimoneBianco\LaravelRagChunks\Services\Converters\NaiveWordConverter;
use SimoneBianco\LaravelRagChunks\Services\Converters\PandocWordConverter;
use SimoneBianco\LaravelRagChunks\Services\Converters\WordConverterInterface;
use SimoneBianco\LaravelRagChunks\Services\DocumentService;
use SimoneBianco\LaravelRagChunks\Services\FileService;
use SimoneBianco\LaravelRagChunks\Services\PostProcessors\PostProcessor;

/**
 * Parses .docx files by first converting them to an intermediate format
 * (plain text or Markdown) and then delegating chunking to the appropriate service.
 *
 * The conversion strategy is controlled by WordParsingStrategy in the context DTO:
 * - NONE   → NaiveWordConverter (phpoffice → .txt → TxtChunkerService)
 * - PANDOC → PandocWordConverter (pandoc CLI → .md → MarkdownChunkerService)
 *
 * The original .docx is deleted after successful conversion.
 */
class WordParser extends AbstractLocalFileParser
{
    public function __construct(
        FileService $fileService,
        protected WordChunkerService $wordChunkerService,
        protected TxtChunkerService $txtChunkerService,
        protected MarkdownChunkerService $markdownChunkerService,
        PostProcessor $postProcessor,
        DocumentService $documentService,
    ) {
        parent::__construct($fileService, $postProcessor, $documentService);
    }

    /**
     * @return string
     */
    protected function getExpectedExtension(): string
    {
        return 'docx';
    }

    /**
     * @param string $relativeDirPath
     * @param string $relativeFilePath
     * @return WordParsingContextDTO
     */
    protected function makeInitialContext(string $relativeDirPath, string $relativeFilePath): ParsingContextDTO
    {
        return new WordParsingContextDTO(
            relativeDirPath: $relativeDirPath,
            relativeFilePath: $relativeFilePath,
        );
    }

    /**
     * Reconstructs a WordParsingContextDTO from the raw process context array.
     * The strategy field is deserialized from the stored string value; defaults to NONE.
     *
     * @param array<string, mixed> $data
     * @return WordParsingContextDTO
     */
    public function contextFromArray(array $data): ParsingContextDTO
    {
        return WordParsingContextDTO::fromArray($data);
    }

    /**
     * Converts the .docx to an intermediate format based on the context strategy,
     * then chunks it and writes refined_output.jsonl.
     *
     * Steps:
     * 1. Pick converter (NaiveWordConverter → .txt, PandocWordConverter → .md).
     * 2. Convert and delete the original .docx.
     * 3. Pick chunker based on converted file extension (txt → TxtChunkerService, md → MarkdownChunkerService).
     * 4. Stream chunks into refined_output.jsonl.
     * 5. Update context with the new relativeFilePath (converted file) and relativeRefinedPath.
     *
     * @param WordParsingContextDTO $context
     * @return WordParsingContextDTO
     * @throws RuntimeException If conversion or chunking fails.
     */
    public function refineOutputJson(ParsingContextDTO $context): ParsingContextDTO
    {
        /** @var WordParsingContextDTO $context */
        $absoluteDocxPath = $this->fileService->getAbsolutePath($context->relativeFilePath);
        $absoluteTargetDir = $this->fileService->getAbsolutePath($context->relativeDirPath);

        // 1. Convert docx → txt or md
        $converter = $this->resolveConverter($context->strategy);
        $convertedAbsolutePath = $converter->convert($absoluteDocxPath, $absoluteTargetDir);

        // Update context to reflect the new intermediate file (for audit/retry purposes)
        $convertedBasename = pathinfo($convertedAbsolutePath, PATHINFO_BASENAME);
        $context->relativeFilePath = $context->relativeDirPath . '/' . $convertedBasename;

        // 2. Chunk the converted file
        $convertedExtension = pathinfo($convertedAbsolutePath, PATHINFO_EXTENSION);

        $writeRelativePath = "$context->relativeDirPath/refined_output.jsonl";
        $writeStream = $this->fileService->writeStream($writeRelativePath, 'w');

        foreach ($this->chunkConverted($convertedAbsolutePath, $convertedExtension) as $rawItems) {
            foreach ($rawItems as $item) {
                $this->fileService->writeOnStream(
                    $writeStream,
                    json_encode($item->toArray(), JSON_UNESCAPED_UNICODE) . "\n"
                );
            }
        }

        $this->fileService->closeStreams($writeStream);

        $context->relativeRefinedPath = $writeRelativePath;

        return $context;
    }

    /**
     * Selects the appropriate converter based on the parsing strategy.
     *
     * @param WordParsingStrategy $strategy
     * @return WordConverterInterface
     */
    protected function resolveConverter(WordParsingStrategy $strategy): WordConverterInterface
    {
        return match ($strategy) {
            WordParsingStrategy::NONE   => new NaiveWordConverter($this->wordChunkerService),
            WordParsingStrategy::PANDOC => new PandocWordConverter(),
        };
    }

    /**
     * Delegates chunking to the correct chunker service based on the converted file extension.
     *
     * @param string $absolutePath Absolute path to the converted file.
     * @param string $extension    Extension of the converted file ('txt' or 'md').
     * @return \Generator
     * @throws RuntimeException If the extension is not supported.
     */
    protected function chunkConverted(string $absolutePath, string $extension): \Generator
    {
        return match ($extension) {
            'txt' => $this->txtChunkerService->chunkTxt($absolutePath),
            'md'  => $this->markdownChunkerService->chunkMarkdown($absolutePath),
            default => throw new RuntimeException("Unsupported converted file extension: $extension"),
        };
    }
}
