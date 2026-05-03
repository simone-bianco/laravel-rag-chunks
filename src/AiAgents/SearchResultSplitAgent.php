<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use InvalidArgumentException;
use RuntimeException;
use SimoneBianco\LaravelAiAgents\Agents\RotableAgent;
use Throwable;
use TypeError;

class SearchResultSplitAgent extends RotableAgent
{
    protected $history = 'LarAgent\\Context\\Drivers\\InMemoryStorage';

    protected $model = 'gpt-5.4-mini';

    protected $maxCompletionTokens = 2400;

    protected string $query = '';

    protected ?string $notes = null;

    protected string $chunkCatalog = '';

    public function withInput(string $query, ?string $notes, string $chunkCatalog): self
    {
        $this->query = trim($query);
        $this->notes = is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
        $this->chunkCatalog = trim($chunkCatalog);

        return $this;
    }

    public function structuredOutput(): array
    {
        return [
            'name' => 'search_result_split',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'groups' => [
                        'type' => 'array',
                        'description' => 'Distinct focused search-result memories produced by splitting the input memory.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string'],
                                'notes' => ['type' => 'string'],
                                'chunk_ids' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                            ],
                            'required' => ['query', 'notes', 'chunk_ids'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['groups'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
You split one saved RAG search result into multiple narrower search-result memories.

INPUT
- query: original memory title/query.
- notes: optional original notes.
- chunk_catalog: every original chunk with id, document, chapter, and short content.

TASK
Create 2 to 6 focused groups. Each group must represent one distinct topic/angle present in the chunks.

RULES
1. Use only chunk IDs present in chunk_catalog.
2. Every output group needs at least one chunk_id.
3. Prefer assigning each chunk to exactly one best group; omit truly off-topic chunks.
4. Groups must be meaningfully different, not paraphrases.
5. Query must be a concise reusable search phrase.
6. Notes must be ultra-short keyword fragments, max 30 words.
7. Never invent topics not supported by the chunks.
8. Return only schema-compliant output.
PROMPT;
    }

    public function respond(?string $message = null): array|string
    {
        if ($this->query === '' || $this->chunkCatalog === '') {
            throw new InvalidArgumentException('[SearchResultSplitAgent] query and chunk catalog are required.');
        }

        $payload = json_encode([
            'query' => $this->query,
            'notes' => $this->notes,
            'chunk_catalog' => $this->chunkCatalog,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($payload) || $payload === '') {
            throw new RuntimeException('[SearchResultSplitAgent] Failed to encode payload.');
        }

        try {
            $method = new \ReflectionMethod(get_parent_class($this), 'respond');
            $arguments = [$payload];

            if ($method->getNumberOfParameters() >= 2) {
                $arguments[] = $this->structuredOutput();
            }

            $response = $method->invokeArgs($this, $arguments);

            if (is_array($response)) {
                return ['groups' => is_array($response['groups'] ?? null) ? $response['groups'] : []];
            }

            throw new RuntimeException('[SearchResultSplitAgent] Unexpected response type from AI provider.');
        } catch (TypeError $exception) {
            throw new RuntimeException('AI provider returned invalid content: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function prompt($message): array|string
    {
        return $message;
    }

    /** @return array<int, array{query: string, notes: ?string, chunk_ids: array<int, string>}> */
    public static function split(string $query, ?string $notes, string $chunkCatalog): array
    {
        try {
            $agent = new self('search-result-split');
            $result = $agent->withInput($query, $notes, $chunkCatalog)->respond();

            if (! is_array($result)) {
                return [];
            }

            return self::normalizeGroups(is_array($result['groups'] ?? null) ? $result['groups'] : []);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<int, mixed> $groups
     * @return array<int, array{query: string, notes: ?string, chunk_ids: array<int, string>}>
     */
    private static function normalizeGroups(array $groups): array
    {
        $normalized = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $query = is_string($group['query'] ?? null) ? trim($group['query']) : '';
            $notes = is_string($group['notes'] ?? null) && trim($group['notes']) !== '' ? trim($group['notes']) : null;
            $chunkIds = is_array($group['chunk_ids'] ?? null) ? $group['chunk_ids'] : [];
            $chunkIds = array_values(array_unique(array_filter(
                array_map(static fn (mixed $id): ?string => is_string($id) ? trim($id) : null, $chunkIds),
                static fn (?string $id): bool => $id !== null && $id !== '',
            )));

            if ($query === '' || $chunkIds === []) {
                continue;
            }

            $normalized[] = ['query' => $query, 'notes' => $notes, 'chunk_ids' => $chunkIds];
        }

        return $normalized;
    }
}
