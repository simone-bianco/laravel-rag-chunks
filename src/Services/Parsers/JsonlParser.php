<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use SimoneBianco\LaravelRagChunks\DTOs\Parsing\Jsonl\JsonlParsingContextDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\ParsingContextDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\RefinedItemDTO;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;

class JsonlParser extends AbstractLocalFileParser
{
    /**
     * @return string
     */
    protected function getExpectedExtension(): string
    {
        return 'jsonl';
    }

    /**
     * @param string $relativeDirPath
     * @param string $relativeFilePath
     * @return JsonlParsingContextDTO
     */
    protected function makeInitialContext(string $relativeDirPath, string $relativeFilePath): ParsingContextDTO
    {
        return new JsonlParsingContextDTO(
            relativeDirPath: $relativeDirPath,
            relativeFilePath: $relativeFilePath,
        );
    }

    /**
     * Reconstructs a JsonlParsingContextDTO from the raw process context array.
     *
     * @param array<string, mixed> $data
     * @return JsonlParsingContextDTO
     */
    public function contextFromArray(array $data): ParsingContextDTO
    {
        return JsonlParsingContextDTO::fromArray($data);
    }

    /**
     * Streams the JSONL file line by line, converts each valid item to a RefinedItemDTO
     * and writes them to refined_output.jsonl.
     *
     * @param JsonlParsingContextDTO $context
     * @return JsonlParsingContextDTO
     * @throws InvalidFileException If the source file cannot be opened.
     */
    public function refineOutputJson(ParsingContextDTO $context): ParsingContextDTO
    {
        /** @var JsonlParsingContextDTO $context */
        $absolutePath = $this->fileService->getAbsolutePath($context->relativeFilePath);

        $stream = @fopen($absolutePath, 'r');
        if ($stream === false) {
            throw new InvalidFileException("Cannot open file: $absolutePath");
        }

        $writeRelativePath = "$context->relativeDirPath/refined_output.jsonl";
        $writeStream = $this->fileService->writeStream($writeRelativePath, 'w');

        try {
            while (($line = fgets($stream)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                $item = json_decode($trimmed, true);
                if (!is_array($item) || empty($item['text'])) {
                    continue;
                }

                $text = $this->buildText($item);
                $figurePath = $item['image'] ?? null;

                $dto = new RefinedItemDTO(text: $text, figurePath: $figurePath);
                $this->fileService->writeOnStream(
                    $writeStream,
                    json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE) . "\n"
                );
            }
        } finally {
            fclose($stream);
        }

        $this->fileService->closeStreams($writeStream);

        $context->relativeRefinedPath = $writeRelativePath;

        return $context;
    }

    /**
     * Builds the chunk text by prepending tag context if tags are present.
     *
     * @param array<string, mixed> $item
     * @return string
     */
    protected function buildText(array $item): string
    {
        $text = trim($item['text']);

        if (!empty($item['tags'])) {
            $tags = is_array($item['tags']) ? implode(', ', $item['tags']) : $item['tags'];
            $text = "[Tags: $tags]\n\n$text";
        }

        return $text;
    }
}
