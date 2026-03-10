<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Concerns;

trait NormalizesChunkIds
{
    private const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /**
     * @param  array<int, mixed>  $chunkIds
     * @return array<int, string>
     */
    protected function normalizeChunkIds(array $chunkIds): array
    {
        $normalized = [];

        foreach ($chunkIds as $id) {
            $uuid = $this->extractUuid($id);

            if ($uuid !== null) {
                $normalized[$uuid] = $uuid;
            }
        }

        return array_values($normalized);
    }

    private function extractUuid(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $candidate = trim($value, " \t\n\r\0\x0B\"'`[](){}<>,.;");

        if ($candidate === '') {
            return null;
        }

        if (preg_match('/^' . self::UUID_PATTERN . '$/', $candidate) === 1) {
            return strtolower($candidate);
        }

        if (preg_match('/^(' . self::UUID_PATTERN . ')[0-9a-fA-F]+$/', $candidate, $match) === 1) {
            return strtolower($match[1]);
        }

        return null;
    }
}
