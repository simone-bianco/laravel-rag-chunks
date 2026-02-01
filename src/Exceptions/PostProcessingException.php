<?php

namespace SimoneBianco\LaravelRagChunks\Exceptions;

use Exception;
use Throwable;

class PostProcessingException extends Exception
{
    public function __construct(
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null,
        protected ?string $exceptionClass = null,
        protected ?string $jsonRelativePath = null,
        protected ?int $jsonLine = null,
        protected ?string $jsonLineContent = null,
        protected ?bool $retryable = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'exception' => $this->exceptionClass,
            'json_relative_path' => $this->jsonRelativePath,
            'json_line' => $this->jsonLine,
            'json_line_content' => $this->jsonLineContent,
            'retryable' => $this->retryable
        ];
    }
}
