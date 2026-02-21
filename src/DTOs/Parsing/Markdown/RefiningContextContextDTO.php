<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parsing\Markdown;

use InvalidArgumentException;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\ParsingContextDTO;

class RefiningContextContextDTO extends ParsingContextDTO
{
    public function __construct(
        public string $relativeDirPath,
        public string $relativeFilePath,
        bool $computeEmbeddings = true,
        bool $postProcess = true,
    ) {
        parent::__construct($computeEmbeddings, $postProcess);
    }

    public function toArray(): array
    {
        return [
            'relative_dir_path' => $this->relativeDirPath,
            'relative_file_path' => $this->relativeFilePath,
        ];
    }

    public static function fromArray(array $data): static
    {
        if (empty($data['relative_dir_path'])) {
            throw new InvalidArgumentException('relative_dir_path is required');
        }

        if (empty($data['relative_file_path'])) {
            throw new InvalidArgumentException('relative_file_path is required');
        }

        return new static($data['relative_dir_path'], $data['relative_file_path']);
    }
}
