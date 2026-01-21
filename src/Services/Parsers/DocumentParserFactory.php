<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use SimoneBianco\LaravelRagChunks\Enums\DocumentExtension;
use SimoneBianco\LaravelRagChunks\Exceptions\ExtensionParsingNotSupportedException;
use SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts\DocumentParserInterface;

class DocumentParserFactory
{
    /**
     * @param string $extension
     * @return DocumentParserInterface
     * @throws ExtensionParsingNotSupportedException
     */
    public static function make(string $extension): DocumentParserInterface
    {
        return match ($extension) {
            DocumentExtension::PDF->value => app(PdfParser::class),
            DocumentExtension::MARKDOWN->value, DocumentExtension::TXT->value => app(MarkdownParser::class),
            default => throw new ExtensionParsingNotSupportedException("'$extension' parsing not supported for parsing"),
        };
    }
}
