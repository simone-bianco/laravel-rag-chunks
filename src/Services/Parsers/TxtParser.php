<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use SimoneBianco\LaravelRagChunks\DTOs\Parsing\ParsingContextDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\RefinedItemDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\Txt\TxtParsingContextDTO;
use SimoneBianco\LaravelRagChunks\Services\Chunkers\TxtChunkerService;
use SimoneBianco\LaravelRagChunks\Services\DocumentService;
use SimoneBianco\LaravelRagChunks\Services\FileService;
use SimoneBianco\LaravelRagChunks\Services\PostProcessors\PostProcessor;

class TxtParser extends AbstractLocalFileParser
{
    public function __construct(
        FileService $fileService,
        protected TxtChunkerService $chunkerService,
        PostProcessor $postProcessor,
        DocumentService $documentService,
    ) {
        parent::__construct($fileService, $postProcessor, $documentService);
    }

    protected function getExpectedExtension(): string
    {
        return 'txt';
    }

    /**
     * @return TxtParsingContextDTO
     */
    protected function makeInitialContext(string $relativeDirPath, string $relativeFilePath): ParsingContextDTO
    {
        return new TxtParsingContextDTO(
            relativeDirPath: $relativeDirPath,
            relativeFilePath: $relativeFilePath,
        );
    }

    /**
     * Reconstructs a TxtParsingContextDTO from the raw process context array.
     *
     * @param  array<string, mixed>  $data
     * @return TxtParsingContextDTO
     */
    public function contextFromArray(array $data): ParsingContextDTO
    {
        return TxtParsingContextDTO::fromArray($data);
    }

    /**
     * Streams the .txt file through TxtChunkerService (character-based chunking),
     * and writes each RefinedItemDTO as a line to refined_output.jsonl.
     *
     * @param  TxtParsingContextDTO  $context
     * @return TxtParsingContextDTO
     */
    public function refineOutputJson(ParsingContextDTO $context): ParsingContextDTO
    {
        /** @var TxtParsingContextDTO $context */
        $writeRelativePath = "$context->relativeDirPath/refined_output.jsonl";
        $writeStream = $this->fileService->writeStream($writeRelativePath, 'w');
        $absolutePath = $this->fileService->getAbsolutePath($context->relativeFilePath);

        /** @var array<RefinedItemDTO> $rawItems */
        foreach ($this->chunkerService->chunkTxt($absolutePath) as $rawItems) {
            foreach ($rawItems as $item) {
                // JSON_INVALID_UTF8_SUBSTITUTE: sostituisce byte invalidi invece di ritornare false
                $encoded = json_encode($item->toArray(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

                if ($encoded === false) {
                    // Fallback di sicurezza: non dovrebbe mai accadere dopo la pulizia nel chunker,
                    // ma se accade lanciamo un'eccezione esplicita invece di scrivere una riga vuota.
                    throw new \RuntimeException(
                        'json_encode failed for chunk: '.json_last_error_msg()
                    );
                }

                $this->fileService->writeOnStream($writeStream, $encoded."\n");
            }
        }

        $this->fileService->closeStreams($writeStream);

        $context->relativeRefinedPath = $writeRelativePath;

        return $context;
    }
}
