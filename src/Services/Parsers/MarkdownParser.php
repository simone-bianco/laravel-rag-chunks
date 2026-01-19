<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts\DocumentParserInterface;

class MarkdownParser implements DocumentParserInterface
{
    public function dispatchParsing(Document $document): void
    {
    }
}
