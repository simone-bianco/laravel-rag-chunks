<?php

namespace SimoneBianco\LaravelRagChunks\Services\Converters;

use RuntimeException;

/**
 * Converts a .docx to Markdown via the pandoc CLI.
 *
 * Pandoc preserves document structure (headings, lists, tables as Markdown tables),
 * which then feeds into the MarkdownChunkerService for context-aware chunking.
 *
 * Requirements:
 * - pandoc must be installed and available in PATH on both Windows and Unix.
 *   See: https://pandoc.org/installing.html
 *
 * Relevant pandoc flags used:
 * - `--wrap=none`   Disables automatic line wrapping (cleaner output for chunking).
 * - `-f docx`       Explicitly declare input format.
 * - `-t markdown`   Output as Pandoc-flavoured Markdown.
 *
 * See: https://pandoc.org/MANUAL.html
 */
class PandocWordConverter implements WordConverterInterface
{
    /**
     * Runs pandoc to convert the .docx to a .md file, then deletes the original .docx.
     *
     * @param string $absoluteDocxPath  Absolute path to the source .docx.
     * @param string $absoluteTargetDir Absolute path of the output directory.
     * @return string                   Absolute path of the generated .md file.
     * @throws RuntimeException         If pandoc exits with a non-zero status.
     */
    public function convert(string $absoluteDocxPath, string $absoluteTargetDir): string
    {
        $basename = pathinfo($absoluteDocxPath, PATHINFO_FILENAME);
        $outputPath = rtrim($absoluteTargetDir, '/\\') . DIRECTORY_SEPARATOR . "$basename.md";

        $command = sprintf(
            'pandoc -f docx -t markdown --wrap=none %s -o %s',
            escapeshellarg($absoluteDocxPath),
            escapeshellarg($outputPath),
        );

        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "Pandoc conversion failed (exit $exitCode): " . implode("\n", $output)
            );
        }

        if (!file_exists($outputPath)) {
            throw new RuntimeException("Pandoc succeeded but output file not found at: $outputPath");
        }

        unlink($absoluteDocxPath);

        return $outputPath;
    }
}
