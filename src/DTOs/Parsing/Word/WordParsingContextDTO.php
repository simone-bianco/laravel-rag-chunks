<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parsing\Word;

use SimoneBianco\LaravelRagChunks\DTOs\Parsing\ParsingContextDTO;
use SimoneBianco\LaravelRagChunks\Enums\WordParsingStrategy;

class WordParsingContextDTO extends ParsingContextDTO
{
    public function __construct(
        public ?string $relativeDirPath = null,
        public ?string $relativeFilePath = null,
        public ?string $relativeRefinedPath = null,
        public ?string $relativePostProcessedPath = null,
        public WordParsingStrategy $strategy = WordParsingStrategy::NONE,
        bool $computeEmbeddings = true,
        bool $postProcess = true,
    ) {
        parent::__construct($computeEmbeddings, $postProcess);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'relative_dir_path'            => $this->relativeDirPath,
            'relative_file_path'           => $this->relativeFilePath,
            'relative_refined_path'        => $this->relativeRefinedPath,
            'relative_post_processed_path' => $this->relativePostProcessedPath,
            'strategy'                     => $this->strategy->value,
        ], fn($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $data
     * @return static
     */
    public static function fromArray(array $data): static
    {
        return new static(
            relativeDirPath:           $data['relative_dir_path'] ?? null,
            relativeFilePath:          $data['relative_file_path'] ?? null,
            relativeRefinedPath:       $data['relative_refined_path'] ?? null,
            relativePostProcessedPath: $data['relative_post_processed_path'] ?? null,
            strategy:                  WordParsingStrategy::tryFrom($data['strategy'] ?? '') ?? WordParsingStrategy::NONE,
        );
    }
}
