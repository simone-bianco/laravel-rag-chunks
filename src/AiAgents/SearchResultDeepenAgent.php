<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use InvalidArgumentException;
use RuntimeException;
use SimoneBianco\LaravelAiAgents\Agents\RotableAgent;
use Throwable;
use TypeError;

class SearchResultDeepenAgent extends RotableAgent
{
    protected $history = 'LarAgent\\Context\\Drivers\\InMemoryStorage';

    protected $model = 'gpt-5.4-mini';

    protected $maxCompletionTokens = 1600;

    protected string $query = '';

    protected ?string $notes = null;

    protected ?string $summary = null;

    protected string $chunkText = '';

    public function withInput(string $query, ?string $notes, ?string $summary, string $chunkText): self
    {
        $this->query = trim($query);
        $this->notes = is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
        $this->summary = is_string($summary) && trim($summary) !== '' ? trim($summary) : null;
        $this->chunkText = trim($chunkText);

        return $this;
    }

    public function structuredOutput(): array
    {
        return [
            'name' => 'search_result_deepen',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'searches' => [
                        'type' => 'array',
                        'description' => 'Focused follow-up retrieval searches that deepen the original memory topic.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string'],
                                'notes' => ['type' => 'string'],
                            ],
                            'required' => ['query', 'notes'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['searches'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
You deepen one saved RAG search result.

INPUT
- query: original memory title/query.
- notes: optional original notes.
- summary: optional compacted summary.
- chunk_text: current supporting content.

TASK
Generate 1 to 4 follow-up searches that retrieve deeper evidence about the same topic(s).

RULES
1. Stay inside the original topic; do not broaden to unrelated lore or adjacent subjects.
2. Each query must ask for missing detail, causes, consequences, locations, actors, chronology, mechanics, or evidence.
3. Avoid duplicate/paraphrase queries.
4. Notes must be ultra-short keyword fragments, max 30 words.
5. Do not request compact or split; only produce retrieval searches.
6. Return only schema-compliant output.
PROMPT;
    }

    public function respond(?string $message = null): array|string
    {
        if ($this->query === '') {
            throw new InvalidArgumentException('[SearchResultDeepenAgent] query is required.');
        }

        $payload = json_encode([
            'query' => $this->query,
            'notes' => $this->notes,
            'summary' => $this->summary,
            'chunk_text' => $this->chunkText,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($payload) || $payload === '') {
            throw new RuntimeException('[SearchResultDeepenAgent] Failed to encode payload.');
        }

        try {
            $method = new \ReflectionMethod(get_parent_class($this), 'respond');
            $arguments = [$payload];

            if ($method->getNumberOfParameters() >= 2) {
                $arguments[] = $this->structuredOutput();
            }

            $response = $method->invokeArgs($this, $arguments);

            if (is_array($response)) {
                return ['searches' => is_array($response['searches'] ?? null) ? $response['searches'] : []];
            }

            throw new RuntimeException('[SearchResultDeepenAgent] Unexpected response type from AI provider.');
        } catch (TypeError $exception) {
            throw new RuntimeException('AI provider returned invalid content: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function prompt($message): array|string
    {
        return $message;
    }

    /** @return array<int, array{query: string, notes: ?string}> */
    public static function searches(string $query, ?string $notes, ?string $summary, string $chunkText): array
    {
        try {
            $agent = new self('search-result-deepen');
            $result = $agent->withInput($query, $notes, $summary, $chunkText)->respond();

            if (! is_array($result)) {
                return [];
            }

            return self::normalizeSearches(is_array($result['searches'] ?? null) ? $result['searches'] : []);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<int, mixed> $searches
     * @return array<int, array{query: string, notes: ?string}>
     */
    private static function normalizeSearches(array $searches): array
    {
        $normalized = [];

        foreach ($searches as $search) {
            if (! is_array($search)) {
                continue;
            }

            $query = is_string($search['query'] ?? null) ? trim($search['query']) : '';
            $notes = is_string($search['notes'] ?? null) && trim($search['notes']) !== '' ? trim($search['notes']) : null;

            if ($query === '') {
                continue;
            }

            $normalized[] = ['query' => $query, 'notes' => $notes];
        }

        return array_values(array_slice($normalized, 0, 4));
    }
}
