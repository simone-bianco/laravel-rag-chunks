<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts;

use SimoneBianco\LaravelRagChunks\Models\Document;

interface DocumentParserInterface
{
    public function dispatchParsing(Document $document): void;
}
