<?php

namespace SimoneBianco\LaravelRagChunks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimoneBianco\LaravelRagChunks\AiAgents\Concerns\NormalizesChunkIds;

class NormalizesChunkIdsTest extends TestCase
{
    public function test_it_keeps_valid_uuids_and_recovers_embedded_uuid_substrings(): void
    {
        $normalizer = new class
        {
            use NormalizesChunkIds;

            public function run(array $chunkIds): array
            {
                return $this->normalizeChunkIds($chunkIds);
            }
        };

        $result = $normalizer->run([
            'a0d0e9f4-f00c-4ab5-bb92-12ba1735a6ed',
            'df418ad4-6016-4483-a3a3-05fed8287b8d0',
            ' 60e539e2-cc5c-4f78-b696-a09ed429c8e3 ',
            'prefix df418ad4-6016-4483-a3a3-05fed8287b8d suffix',
            'bad-id',
            123,
            '',
        ]);

        $this->assertSame([
            'a0d0e9f4-f00c-4ab5-bb92-12ba1735a6ed',
            'df418ad4-6016-4483-a3a3-05fed8287b8d',
            '60e539e2-cc5c-4f78-b696-a09ed429c8e3',
        ], $result);
    }

    public function test_it_removes_duplicates_after_normalization(): void
    {
        $normalizer = new class
        {
            use NormalizesChunkIds;

            public function run(array $chunkIds): array
            {
                return $this->normalizeChunkIds($chunkIds);
            }
        };

        $result = $normalizer->run([
            'A0D0E9F4-F00C-4AB5-BB92-12BA1735A6ED',
            'a0d0e9f4-f00c-4ab5-bb92-12ba1735a6ed',
            'a0d0e9f4-f00c-4ab5-bb92-12ba1735a6ed,',
        ]);

        $this->assertSame(['a0d0e9f4-f00c-4ab5-bb92-12ba1735a6ed'], $result);
    }

    public function test_it_drops_malformed_uuid_values(): void
    {
        $normalizer = new class
        {
            use NormalizesChunkIds;

            public function run(array $chunkIds): array
            {
                return $this->normalizeChunkIds($chunkIds);
            }
        };

        $result = $normalizer->run([
            '7f955b8c-c406-4cf7-a297-8a7420cf7b9',
            '7f955b8c-c406-4cf7-a297-8a7420cf7b90',
        ]);

        $this->assertSame(['7f955b8c-c406-4cf7-a297-8a7420cf7b90'], $result);
    }

    public function test_it_recovers_uuid_keys_from_relevant_chunk_maps(): void
    {
        $normalizer = new class
        {
            use NormalizesChunkIds;

            public function run(array $chunkIds): array
            {
                return $this->normalizeChunkIds($chunkIds);
            }
        };

        $result = $normalizer->run([
            'df418ad4-6016-4483-a3a3-05fed8287b8d' => ['content' => 'Mapped chunk payload'],
            'not-a-uuid' => ['content' => 'Invalid mapped chunk payload'],
        ]);

        $this->assertSame(['df418ad4-6016-4483-a3a3-05fed8287b8d'], $result);
    }
}
