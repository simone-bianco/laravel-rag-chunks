<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parsing\Pdf;

use SimoneBianco\LaravelRagChunks\DTOs\Parsing\ParsingContextDTO;

class PdfParsingContextDTO extends ParsingContextDTO
{
    public function __construct(
        public ?string $jobId = null,
        public ?string $relativeDirPath = null,
        public ?string $relativeRefinedPath = null,
        public ?string $relativePostProcessedPath = null,
        bool $computeEmbeddings = true,
        bool $postProcess = true,
    ) {
        parent::__construct($computeEmbeddings, $postProcess);
    }

    public function toArray(): array
    {
        return array_filter([
            'job_id' => $this->jobId,
            'relative_dir_path' => $this->relativeDirPath,
            'relative_refined_path' => $this->relativeRefinedPath,
            'relative_post_processed_path' => $this->relativePostProcessedPath,
        ], fn($v) => $v !== null);
    }

    public static function fromArray(array $data): static
    {
        return new static(
            $data['job_id'] ?? null,
            $data['relative_dir_path'] ?? null,
            $data['relative_refined_path'] ?? null,
            $data['relative_post_processed_path'] ?? null,
        );
    }
}
