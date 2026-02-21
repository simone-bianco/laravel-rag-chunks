<?php

namespace SimoneBianco\LaravelRagChunks\Services\Chunkers;

use Generator;
use RuntimeException;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\RefinedItemDTO;

class TxtChunkerService
{
    public function __construct(
        protected int $chunkSize = 1000,
        protected int $generatorChunkSize = 50,
    ) {}

    /**
     * Entry point. Streams a plain-text file line by line to avoid loading large files into memory,
     * accumulates characters up to $chunkSize, and yields batches of RefinedItemDTO.
     *
     * @param string $absolutePath Absolute path to the .txt file.
     * @return Generator<int, array<RefinedItemDTO>>
     * @throws RuntimeException If the file cannot be opened.
     */
    public function chunkTxt(string $absolutePath): Generator
    {
        $stream = @fopen($absolutePath, 'r');

        if ($stream === false) {
            throw new RuntimeException("Cannot open file: $absolutePath");
        }

        try {
            yield from $this->yieldChunksFromStream($stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Reads the stream line by line, fills a character buffer up to $chunkSize,
     * flushes it as a RefinedItemDTO when full, and emits batches via the generator.
     *
     * @param resource $stream
     * @return Generator<int, array<RefinedItemDTO>>
     */
    protected function yieldChunksFromStream($stream): Generator
    {
        $accumulator = [];
        $currentBuffer = '';

        while (($line = fgets($stream)) !== false) {
            $currentBuffer .= $line;

            // Flush the buffer whenever it has reached the chunk size threshold
            while (strlen($currentBuffer) >= $this->chunkSize) {
                $chunk = substr($currentBuffer, 0, $this->chunkSize);
                $currentBuffer = substr($currentBuffer, $this->chunkSize);

                $trimmed = trim($chunk);
                if ($trimmed !== '') {
                    $accumulator[] = new RefinedItemDTO(text: $trimmed);

                    if (count($accumulator) >= $this->generatorChunkSize) {
                        yield $accumulator;
                        $accumulator = [];
                    }
                }
            }
        }

        // Flush remaining buffer content (last partial chunk)
        $trimmed = trim($currentBuffer);
        if ($trimmed !== '') {
            $accumulator[] = new RefinedItemDTO(text: $trimmed);
        }

        if (!empty($accumulator)) {
            yield $accumulator;
        }
    }
}
