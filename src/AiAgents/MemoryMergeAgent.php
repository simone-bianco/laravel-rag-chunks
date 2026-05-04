<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use InvalidArgumentException;
use RuntimeException;
use SimoneBianco\LaravelAiAgents\Agents\RotableAgent;
use Throwable;
use TypeError;

/**
 * Merges multiple search memory queries and notes into a single coherent result.
 *
 * Given a cluster of similar memories (queries + notes), produces a unified
 * query that captures the intent of all members, and unified notes that
 * consolidate information without redundancy.
 *
 * Receives project context (name + description) so the LLM understands the domain
 * and avoids generic noise like "in fantasy" in merged queries.
 */
class MemoryMergeAgent extends RotableAgent
{
    protected $history = 'LarAgent\\Context\\Drivers\\InMemoryStorage';

    protected $model = 'gpt-4o-mini';

    /** @var array<int, array{query: string, notes: ?string}> */
    protected array $memories = [];

    protected string $projectName = '';
    protected ?string $projectDescription = null;

    public function withMemories(array $memories, string $projectName, ?string $projectDescription = null): self
    {
        $this->memories = [];

        foreach ($memories as $memory) {
            if (! is_array($memory)) {
                continue;
            }

            $query = trim((string) ($memory['query'] ?? ''));
            $notes = isset($memory['notes']) && is_string($memory['notes']) && trim($memory['notes']) !== ''
                ? trim($memory['notes'])
                : null;

            if ($query === '') {
                continue;
            }

            $this->memories[] = [
                'query' => $query,
                'notes' => $notes,
            ];
        }

        $this->projectName = $projectName;
        $this->projectDescription = $projectDescription;

        return $this;
    }

    public function structuredOutput(): array
    {
        return [
            'name' => 'merge_memories',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'A single unified search query that captures the core intent of all input queries.',
                    ],
                    'notes' => [
                        'type' => 'string',
                        'description' => 'Consolidated notes preserving all factual information from input notes, without redundancy. Empty string if no notes.',
                    ],
                ],
                'required' => ['query', 'notes'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function instructions(): string
    {
        $contextParts = ["Project: {$this->projectName}"];
        if ($this->projectDescription !== null && $this->projectDescription !== '') {
            $contextParts[] = "Description: {$this->projectDescription}";
        }
        $context = implode("\n", $contextParts);

        return <<<PROMPT
{$context}

You are a search memory compaction agent working on the project described above.

Your task: merge multiple similar search memories into a single coherent result.

Rules:
1. The merged query must capture the core search intent within THIS project's domain. If queries differ, combine concepts with natural language — not pipes or slashes. Prefer a natural search phrase that makes sense in the project context.
2. The merged notes must consolidate all factual information without redundancy.
3. Notes style MUST be caveman: keyword-only fragments, no prose sentences, no fluff.
4. Notes length MUST be at most 30 words.
5. Never invent information not present in the input.
6. Keep the merged query concise (under 100 characters when possible).
7. If all input notes are null or empty, generate a very short caveman note from the merged query topic.
8. The output query should read like a natural search a user would type, not a concatenation.
9. NEVER add generic domain classifiers like "in fantasy", "in D&D", "in RPG" — the project context already establishes the domain.
10. Never output markdown, bullets, or labels like "topic:".
PROMPT;
    }

    public function respond(?string $message = null): array|string
    {
        if (empty($this->memories)) {
            throw new InvalidArgumentException('[MemoryMergeAgent] No memories provided for merge.');
        }

        if (count($this->memories) === 1) {
            return [
                'query' => $this->memories[0]['query'],
                'notes' => $this->memories[0]['notes'] ?? '',
            ];
        }

        $payload = json_encode($this->memories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($payload) || $payload === '') {
            throw new RuntimeException('[MemoryMergeAgent] Failed to encode memories payload.');
        }

        try {
            $method = new \ReflectionMethod(get_parent_class($this), 'respond');
            $arguments = [$payload];

            if ($method->getNumberOfParameters() >= 2) {
                $arguments[] = $this->structuredOutput();
            }

            $response = $method->invokeArgs($this, $arguments);

            if (is_array($response)) {
                return [
                    'query' => trim((string) ($response['query'] ?? '')),
                    'notes' => trim((string) ($response['notes'] ?? '')),
                ];
            }

            throw new RuntimeException('[MemoryMergeAgent] Unexpected response type from AI provider.');
        } catch (TypeError $exception) {
            throw new RuntimeException('AI provider returned invalid content: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function prompt($message): array|string
    {
        return $message;
    }

    /**
     * Merge an array of queries and notes using the AI agent.
     * Falls back to pipe/dash concatenation if the agent fails.
     *
     * @param array<int, array{query: ?string, notes: ?string}> $memories
     * @return array{query: ?string, notes: ?string}
     */
    public static function merge(array $memories, string $projectName, ?string $projectDescription = null): array
    {
        $filtered = [];

        foreach ($memories as $memory) {
            $query = is_string(($memory['query'] ?? null)) ? trim($memory['query']) : null;
            $notes = is_string(($memory['notes'] ?? null)) && trim($memory['notes']) !== '' ? trim($memory['notes']) : null;

            if ($query !== null && $query !== '') {
                $filtered[] = ['query' => $query, 'notes' => $notes];
            }
        }

        if (count($filtered) === 0) {
            return ['query' => null, 'notes' => null];
        }

        if (count($filtered) === 1) {
            return ['query' => $filtered[0]['query'], 'notes' => $filtered[0]['notes']];
        }

        try {
            $agent = new self('memory-merge');
            $result = $agent->withMemories($filtered, $projectName, $projectDescription)->respond();

            if (is_array($result) && isset($result['query']) && trim($result['query']) !== '') {
                return [
                    'query' => $result['query'],
                    'notes' => $result['notes'] !== '' ? $result['notes'] : null,
                ];
            }
        } catch (Throwable) {
            // Fall through to deterministic merge
        }

        // Fallback: deterministic pipe/dash concatenation
        $queries = array_filter(array_map(static fn (array $m): ?string => $m['query'], $filtered));
        $notes = array_filter(array_map(static fn (array $m): ?string => $m['notes'], $filtered));

        return [
            'query' => implode(' | ', array_unique($queries)) ?: null,
            'notes' => implode("\n---\n", array_unique($notes)) ?: null,
        ];
    }
}
