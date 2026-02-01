<?php

namespace SimoneBianco\LaravelRagChunks\Services;

class HashService
{
    public function hash(string $text): string
    {
        return hash('sha256', $text);
    }

    /**
     * Calculate SHA256 hash of a file's contents.
     *
     * @param string $filePath Absolute path to the file
     * @return string The SHA256 hash of the file content
     * @throws \RuntimeException If the file cannot be read
     */
    public function hashFile(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        $hash = hash_file('sha256', $filePath);

        if ($hash === false) {
            throw new \RuntimeException("Failed to calculate hash for file: {$filePath}");
        }

        return $hash;
    }
}
