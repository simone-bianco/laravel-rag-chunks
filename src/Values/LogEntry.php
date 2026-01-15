<?php

namespace SimoneBianco\LaravelRagChunks\Values;

use Illuminate\Contracts\Support\Arrayable;
use Carbon\Carbon;

class LogEntry implements Arrayable
{
    public function __construct(
        public string $severity,
        public string $content,
        public Carbon $timestamp,
        public array $context = []
    ) {}

    public function toArray(): array
    {
        return [
            'severity'  => $this->severity,
            'content'   => $this->content,
            'timestamp' => $this->timestamp->toDateTimeString(),
            'context'   => $this->context,
        ];
    }

    public function toString(): string
    {
        $env = config('app.env', 'production');

        $message = sprintf(
            "[%s] %s.%s: %s",
            $this->timestamp->toDateTimeString(),
            $env,
            strtoupper($this->severity),
            $this->content
        );

        if (!empty($this->context)) {
            $message .= ' ' . json_encode($this->context);
        }

        return $message;
    }
}
