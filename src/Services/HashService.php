<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use RuntimeException;

class HashService
{
    public function hash(string $text): string
    {
        return hash('sha256', $text);
    }

    /**
     * Calculate SHA256 hash of a file's contents.
     *
     * @param string $absoluteFilePath Absolute path to the file
     * @return string The SHA256 hash of the file content
     * @throws RuntimeException If the file cannot be read
     */
    public function hashFile(string $absoluteFilePath): string
    {
        if (!file_exists($absoluteFilePath)) {
            throw new RuntimeException("File not found: {$absoluteFilePath}");
        }

        $hash = hash_file('sha256', $absoluteFilePath);

        if ($hash === false) {
            throw new RuntimeException("Failed to calculate hash for file: {$absoluteFilePath}");
        }

        return $hash;
    }
}
