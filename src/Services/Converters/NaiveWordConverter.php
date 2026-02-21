<?php

namespace SimoneBianco\LaravelRagChunks\Services\Converters;

use RuntimeException;
use SimoneBianco\LaravelRagChunks\Services\Chunkers\WordChunkerService;

/**
 * Converts a .docx to a plain-text .txt file using phpoffice/phpword.
 *
 * No external tools required. Structure (headings, tables) is lost;
 * only raw paragraph text is preserved. Use this strategy when
 * semantic chunking quality matters less than simplicity.
 */
class NaiveWordConverter implements WordConverterInterface
{
    public function __construct(protected WordChunkerService $chunkerService) {}

    /**
     * Extracts all text from the .docx via phpoffice, writes it as a .txt file
     * in the same target directory, then deletes the original .docx.
     *
     * @param string $absoluteDocxPath  Absolute path to the source .docx.
     * @param string $absoluteTargetDir Absolute path of the output directory.
     * @return string                   Absolute path of the generated .txt file.
     * @throws RuntimeException         If the .docx cannot be read or the .txt cannot be written.
     */
    public function convert(string $absoluteDocxPath, string $absoluteTargetDir): string
    {
        $text = $this->chunkerService->extractFullText($absoluteDocxPath);

        $basename = pathinfo($absoluteDocxPath, PATHINFO_FILENAME);
        $outputPath = rtrim($absoluteTargetDir, '/\\') . DIRECTORY_SEPARATOR . "$basename.txt";

        if (file_put_contents($outputPath, $text) === false) {
            throw new RuntimeException("Failed to write converted text to: $outputPath");
        }

        unlink($absoluteDocxPath);

        return $outputPath;
    }
}
