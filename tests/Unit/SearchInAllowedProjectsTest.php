<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchInAllowedProjects;

final class SearchInAllowedProjectsTest extends TestCase
{
    public function test_execute_accepts_plain_allowed_alias_with_empty_searches(): void
    {
        $tool = new SearchInAllowedProjects(['spunti-e-esempi-fantasy']);

        $result = $tool->execute([
            'projectAlias' => 'spunti-e-esempi-fantasy',
            'persistentKey' => 'abc12',
            'searches' => [],
        ]);

        $this->assertSame(['results' => []], $result);
    }

    public function test_execute_accepts_hash_prefixed_alias_with_empty_searches(): void
    {
        $tool = new SearchInAllowedProjects(['spunti-e-esempi-fantasy']);

        $result = $tool->execute([
            'projectAlias' => '#spunti-e-esempi-fantasy',
            'persistentKey' => 'abc12',
            'searches' => [],
        ]);

        $this->assertSame(['results' => []], $result);
    }

    public function test_execute_accepts_case_insensitive_alias_with_empty_searches(): void
    {
        $tool = new SearchInAllowedProjects(['dungeons-and-dragons-5e-QltevO']);

        $result = $tool->execute([
            'projectAlias' => '#DUNGEONS-AND-DRAGONS-5E-qltevo',
            'persistentKey' => 'abc12',
            'searches' => [],
        ]);

        $this->assertSame(['results' => []], $result);
    }

    public function test_execute_rejects_unknown_alias(): void
    {
        $tool = new SearchInAllowedProjects(['spunti-e-esempi-fantasy']);

        $this->expectException(InvalidArgumentException::class);

        $tool->execute([
            'projectAlias' => '#non-allowed-project',
            'persistentKey' => 'abc12',
            'searches' => [],
        ]);
    }
}
