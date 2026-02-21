<?php

namespace SimoneBianco\LaravelRagChunks\Enums;

enum WordParsingStrategy: string
{
    /**
     * Naive strategy: extracts raw text via phpoffice/phpword,
     * writes it to a .txt file, and processes it as plain text.
     */
    case NONE = 'none';

    /**
     * Pandoc strategy: converts the .docx to Markdown via the pandoc CLI,
     * preserving headings and structure, then processes it as Markdown.
     */
    case PANDOC = 'pandoc';
}
