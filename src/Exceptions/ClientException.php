<?php

namespace SimoneBianco\LaravelRagChunks\Exceptions;

use Exception;
use Throwable;

class ClientException extends Exception
{
    private const array RETRYABLE_CODES = [
        408 => true,
        429 => true,
        500 => true,
        502 => true,
        503 => true,
        504 => true,
    ];

    public function __construct(
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null,
        protected ?string $exceptionClass = null,
        protected ?array $response = [],
        protected ?bool $retryable = null
    ) {
        parent::__construct($message, $code, $previous);

        if ($this->retryable === null) {
            $this->retryable = $this->determineIfRetryable($code);
        }
    }

    public static function makeFromException(Throwable $exception): self
    {
        $responseData = [];
        if (method_exists($exception, 'getResponse')) {
            $rawResponse = $exception->getResponse();

            if (is_array($rawResponse)) {
                $responseData = $rawResponse;
            } elseif (is_string($rawResponse)) {
                $responseData = json_decode($rawResponse, true) ?? ['raw_body' => $rawResponse];
            } elseif (is_object($rawResponse) && method_exists($rawResponse, 'toArray')) {
                $responseData = $rawResponse->toArray();
            }
        }

        $statusCode = $exception->getCode();

        if (empty($statusCode) || !is_int($statusCode)) {
            $statusCode = $responseData['status'] ?? $responseData['code'] ?? 500;
        }

        return new self(
            message: $exception->getMessage(),
            code: (int) $statusCode,
            previous: $exception,
            exceptionClass: get_class($exception),
            response: $responseData,
            retryable: null
        );
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function getResponse(): array
    {
        return $this->response;
    }

    public function getResponseBody(): mixed
    {
        return $this->response['body']
            ?? $this->response['data']
            ?? $this->response['message']
            ?? null;
    }

    public function context(): array
    {
        return [
            'is_retryable' => $this->isRetryable(),
            'response_code' => $this->getCode(),
            'response_body' => $this->getResponseBody(),
            'full_response' => $this->response,
        ];
    }

    protected function determineIfRetryable(int $code): bool
    {
        return isset(self::RETRYABLE_CODES[$code]);
    }
}
