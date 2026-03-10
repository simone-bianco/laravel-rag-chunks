<?php

namespace SimoneBianco\LaravelRagChunks\Services\Chunkers;

use Generator;
use RuntimeException;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\RefinedItemDTO;

class TxtChunkerService
{
    /**
     * PDF ligature characters and other typographic Unicode that should be
     * replaced with their plain ASCII equivalents before chunking.
     */
    private const array LIGATURE_MAP = [
        "\u{FB00}" => 'ff',   // ﬀ
        "\u{FB01}" => 'fi',   // ﬁ
        "\u{FB02}" => 'fl',   // ﬂ
        "\u{FB03}" => 'ffi',  // ﬃ
        "\u{FB04}" => 'ffl',  // ﬄ
        "\u{FB05}" => 'ft',   // ﬅ
        "\u{FB06}" => 'st',   // ﬆ
        "\u{2019}" => "'",    // right single quotation mark
        "\u{2018}" => "'",    // left single quotation mark
        "\u{201C}" => '"',    // left double quotation mark
        "\u{201D}" => '"',    // right double quotation mark
        "\u{2013}" => '-',    // en dash
        "\u{2014}" => '--',   // em dash
        "\u{2026}" => '...',  // horizontal ellipsis
        "\u{00AD}" => '',     // soft hyphen (invisible, causes encoding issues)
        "\u{FEFF}" => '',     // BOM / zero-width no-break space
    ];

    public function __construct(
        protected int $chunkSize = 700,
        protected int $generatorChunkSize = 50,
    ) {}

    /**
     * Entry point. Streams a plain-text file line by line to avoid loading large files into memory,
     * accumulates characters up to $chunkSize, and yields batches of RefinedItemDTO.
     *
     * Opens in binary mode ('rb') to prevent Windows text-mode from silently
     * dropping or mangling bytes before we have a chance to clean them ourselves.
     *
     * @param string $absolutePath Absolute path to the .txt file.
     * @return Generator<int, array<RefinedItemDTO>>
     * @throws RuntimeException If the file cannot be opened.
     */
    public function chunkTxt(string $absolutePath): Generator
    {
        // 'rb' = binary mode: no \r\n → \n conversion on Windows, no early stops on \0
        $stream = @fopen($absolutePath, 'rb');

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
     * Reads the stream line by line, cleans each line, fills a character buffer
     * up to $chunkSize, flushes it as a RefinedItemDTO when full, and emits
     * batches via the generator.
     *
     * @param resource $stream
     * @return Generator<int, array<RefinedItemDTO>>
     */
    protected function yieldChunksFromStream($stream): Generator
    {
        $accumulator = [];
        $currentBuffer = '';

        while (($line = fgets($stream)) !== false) {
            $currentBuffer .= $this->cleanText($line);

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

    /**
     * Cleans a raw line of text extracted from a .txt / PDF-exported file:
     *
     *  1. Removes null bytes that stop some readers prematurely.
     *  2. Normalises Windows line endings to Unix (\r\n → \n, lone \r → \n).
     *  3. Replaces known PDF ligatures and typographic Unicode (ﬁ→fi, ﬂ→fl …).
     *  4. Re-encodes to UTF-8, substituting or dropping any invalid sequences
     *     so that json_encode() never silently returns false.
     *  5. Strips C0/C1 control characters (except \t, \n, \r which are meaningful).
     *
     * @param string $line Raw line from fgets().
     * @return string Clean, guaranteed-valid UTF-8 text.
     */
    private function cleanText(string $line): string
    {
        // 1. Remove null bytes
        $line = str_replace("\0", '', $line);

        // 2. Normalise line endings
        $line = str_replace(["\r\n", "\r"], "\n", $line);

        // 3. Replace PDF ligatures and typographic characters
        $line = str_replace(
            array_keys(self::LIGATURE_MAP),
            array_values(self::LIGATURE_MAP),
            $line
        );

        // 4. Ensure valid UTF-8 (invalid byte sequences become the replacement char U+FFFD,
        //    then we strip those too so we end up with clean ASCII-range + valid Unicode only)
        $line = mb_convert_encoding($line, 'UTF-8', 'UTF-8');
        // Drop the replacement character that mb_convert_encoding inserts for bad bytes
        $line = str_replace("\u{FFFD}", '', $line);

        // 5. Strip C0 control chars (0x00–0x1F) except TAB (0x09), LF (0x0A), CR (0x0D)
        //    and C1 control chars (0x7F–0x9F)
        $line = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x80-\x9F]/u', '', $line);

        return $line;
    }
}
