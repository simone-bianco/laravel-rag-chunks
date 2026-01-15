<?php

namespace SimoneBianco\LaravelRagChunks\Values;

use Illuminate\Support\Collection;

class LogCollection extends Collection
{
    public function addLog(string $severity, string $content, array $context = []): self
    {
        $this->push(new LogEntry(
            $severity,
            $content,
            now(),
            $context
        ));

        return $this;
    }

    public function info(string $content, array $context = []): static
    {
        return $this->addLog('INFO', $content, $context);
    }

    public function error(string $content, array $context = []): static
    {
        return $this->addLog('ERROR', $content, $context);
    }

    public function warning(string $content, array $context = []): static
    {
        return $this->addLog('WARNING', $content, $context);
    }

    public function logErrors(): static
    {
        return $this->filter(function (LogEntry $entry) {
            return $entry->severity === 'ERROR';
        });
    }

    public function errors(): static
    {
        return $this->logErrors()->map(function (LogEntry $entry) {
            return $entry->content;
        });
    }

    public function toFormattedString(): string
    {
        return $this->map(fn (LogEntry $entry) => $entry->toString())->implode(PHP_EOL);
    }

    public function __toString(): string
    {
        return $this->toFormattedString();
    }
}
