<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use SimoneBianco\LaravelRagChunks\DTOs\Parsing\Json\JsonParsingContextDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\ParsingContextDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\RefinedItemDTO;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;

class JsonParser extends AbstractLocalFileParser
{
    /**
     * @return string
     */
    protected function getExpectedExtension(): string
    {
        return 'json';
    }

    /**
     * @param string $relativeDirPath
     * @param string $relativeFilePath
     * @return JsonParsingContextDTO
     */
    protected function makeInitialContext(string $relativeDirPath, string $relativeFilePath): ParsingContextDTO
    {
        return new JsonParsingContextDTO(
            relativeDirPath: $relativeDirPath,
            relativeFilePath: $relativeFilePath,
        );
    }

    /**
     * Reconstructs a JsonParsingContextDTO from the raw process context array.
     *
     * @param array<string, mixed> $data
     * @return JsonParsingContextDTO
     */
    public function contextFromArray(array $data): ParsingContextDTO
    {
        return JsonParsingContextDTO::fromArray($data);
    }

    /**
     * Reads the JSON file, converts each item to a RefinedItemDTO and writes refined_output.jsonl.
     * Supports both a single JSON object and an array of objects (each must have a 'text' field).
     *
     * @param JsonParsingContextDTO $context
     * @return JsonParsingContextDTO
     * @throws InvalidFileException If the file contains invalid JSON.
     */
    public function refineOutputJson(ParsingContextDTO $context): ParsingContextDTO
    {
        /** @var JsonParsingContextDTO $context */
        $absolutePath = $this->fileService->getAbsolutePath($context->relativeFilePath);

        $raw = file_get_contents($absolutePath);
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new InvalidFileException("Invalid JSON in: $absolutePath");
        }

        // Supports both a single object and an array of objects
        $items = isset($data['text']) ? [$data] : $data;

        $writeRelativePath = "$context->relativeDirPath/refined_output.jsonl";
        $writeStream = $this->fileService->writeStream($writeRelativePath, 'w');

        foreach ($items as $item) {
            if (empty($item['text'])) {
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
