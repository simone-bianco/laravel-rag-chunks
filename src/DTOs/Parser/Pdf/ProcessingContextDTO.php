<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parser\Pdf;

use InvalidArgumentException;
use SimoneBianco\LaravelRagChunks\DTOs\Parser\BaseContextDTO;

class ProcessingContextDTO extends BaseContextDTO
{
    public function __construct(
        public string $relativeDirPath
    ) {}

    public function toArray(): array
    {
        return [
            'relative_dir_path' => $this->relativeDirPath,
        ];
    }

    public static function fromArray(array $data): static
    {
        if (empty($data['relative_dir_path'])) {
            throw new InvalidArgumentException('relative_dir_path is required');
        }

        return new static($data['relative_dir_path']);
    }
}
