<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Concerns;

trait NormalizesChunkIds
{
    private const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /**
     * @param  mixed  $chunkIds
     * @return array<int, string>
     */
    protected function normalizeChunkIds(mixed $chunkIds): array
    {
        if (! is_array($chunkIds)) {
            return [];
        }

        $normalized = [];

        foreach ($chunkIds as $key => $value) {
            foreach ([$value, $key] as $candidate) {
                $uuid = $this->extractUuid($candidate);

                if ($uuid !== null) {
                    $normalized[$uuid] = $uuid;
                    break;
                }
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

        if (preg_match('/(' . self::UUID_PATTERN . ')/', $candidate, $match) === 1) {
            return strtolower($match[1]);
        }

        return null;
    }
}
