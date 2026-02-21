<?php

namespace SimoneBianco\LaravelRagChunks\Services\Converters;

interface WordConverterInterface
{
    /**
     * Converts a .docx file to an intermediate format (txt or md),
     * deletes the original .docx after conversion, and returns the
     * absolute path of the newly created file.
     *
     * @param string $absoluteDocxPath  Absolute path to the source .docx file.
     * @param string $absoluteTargetDir Absolute path of the directory to write the output into.
     * @return string                   Absolute path of the converted file.
     * @throws \RuntimeException        If conversion fails.
     */
    public function convert(string $absoluteDocxPath, string $absoluteTargetDir): string;
}
