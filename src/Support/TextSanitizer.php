<?php

namespace SimoneBianco\LaravelRagChunks\Support;

class TextSanitizer
{
    /**
     * Sanitizes text to ensure it can be safely encoded to JSON and processed by external APIs.
     * Removes invalid UTF-8 sequences and control characters (except common whitespace).
     *
     * @param string|null $text
     * @return string
     */
    public static function sanitizeForJson(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // Ensure valid UTF-8, fixing any invalid byte sequences
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Remove ASCII control characters except \n (0x0A), \r (0x0D), and \t (0x09)
        // Since we are matching bytes < 0x80, this is safe in UTF-8 without the /u modifier
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Sanity check to avoid returning null if preg_replace fails
        return $text ?? '';
    }
}
