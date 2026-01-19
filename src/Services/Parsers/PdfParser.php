<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use SimoneBianco\DolphinParser\Facades\DolphinParser;
use SimoneBianco\LaravelRagChunks\Exceptions\ParsingDispatchException;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts\DocumentParserInterface;

class PdfParser implements DocumentParserInterface
{
    public function dispatchParsing(Document $document): void
    {
        $response = DolphinParser::parseFileAsync($document->getAbsolutePath());

        if (!$response->jobId || $response->isFailed()) {
            throw new ParsingDispatchException(
                message: "Parsing dispatch failed for document $document->alias",
                response: $response->toArray(),
            );
        }


    }
}
