<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parsing\Pdf;

use InvalidArgumentException;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\BaseContextDTO;

class PollingContextDTO extends BaseContextDTO
{
    public function __construct(
        public string $jobId
    ) {}

    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
        ];
    }

    public static function fromArray(array $data): static
    {
        if (empty($data['job_id'])) {
            throw new InvalidArgumentException('job_id is required');
        }

        return new static($data['job_id']);
    }
}
