<?php

namespace SimoneBianco\LaravelRagChunks\Exceptions;

use Exception;
use Throwable;

class ParsingPollingException extends Exception
{
    public function __construct(
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null,
        protected array $response = []
    ) {
        parent::__construct($message, $code, $previous);
    }
}
