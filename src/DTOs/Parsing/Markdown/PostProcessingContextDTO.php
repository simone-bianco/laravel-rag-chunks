<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parsing\Markdown;

use InvalidArgumentException;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\BaseContextDTO;

class PostProcessingContextDTO extends BaseContextDTO
{
    public function __construct(
        public string $relativeDirPath,
        public string $relativeRefinedPath,
        public ?string $relativePostProcessedPath = null,
    ) {}

    public function toArray(): array
    {
        return [
            'relative_dir_path' => $this->relativeDirPath,
            'relative_refined_path' => $this->relativeRefinedPath,
            'relative_post_processed_path' => $this->relativePostProcessedPath,
        ];
    }

    public static function fromArray(array $data): static
    {
        if (empty($data['relative_dir_path'])) {
            throw new InvalidArgumentException('relative_dir_path is required');
        }

        if (empty($data['relative_refined_path'])) {
            throw new InvalidArgumentException('relative_refined_path is required');
        }

        return new static(
            $data['relative_dir_path'],
            $data['relative_refined_path'],
                $data['relative_post_processed_path'] ?? null
        );
    }
}
