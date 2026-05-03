<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use InvalidArgumentException;
use RuntimeException;
use SimoneBianco\LaravelAiAgents\Agents\RotableAgent;
use Throwable;
use TypeError;

class SearchResultSummaryAgent extends RotableAgent
{
    private const MAX_SUMMARY_WORDS = 260;

    protected $history = 'LarAgent\\Context\\Drivers\\InMemoryStorage';

    protected $model = 'gpt-5.4-mini';

    protected $maxCompletionTokens = 1200;

    protected string $query = '';

    protected ?string $notes = null;

    protected string $chunkText = '';

    public function withInput(string $query, ?string $notes, string $chunkText): self
    {
        $this->query = trim($query);
        $this->notes = is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
        $this->chunkText = trim($chunkText);

        return $this;
    }

    public function structuredOutput(): array
    {
        return [
            'name' => 'search_result_summary',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'summary' => [
                        'type' => 'string',
                        'description' => 'Dense summary containing only details relevant to query and notes.',
                    ],
                ],
                'required' => ['summary'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
You compact saved RAG search results.

INPUT
- query: the saved search-result title/query.
- notes: optional search-result notes.
- chunk_text: all retrieved chunk contents, already grouped by document.

TASK
Write one dense summary containing ONLY information relevant to the query and notes.

RULES
1. Ignore off-topic material even if it appears in chunk_text.
2. Preserve names, places, dates, numbers, constraints, causal links, and concrete facts.
3. Do not mention that you are summarizing chunks.
4. Do not invent missing facts.
5. Prefer compact paragraphs; bullets are allowed only when they improve density.
6. Max 260 words.
7. If there is no relevant information, return: No relevant information found.
PROMPT;
    }

    public function respond(?string $message = null): array|string
    {
        if ($this->query === '' || $this->chunkText === '') {
            throw new InvalidArgumentException('[SearchResultSummaryAgent] query and chunk text are required.');
        }

        $payload = json_encode([
            'query' => $this->query,
            'notes' => $this->notes,
            'chunk_text' => $this->chunkText,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($payload) || $payload === '') {
            throw new RuntimeException('[SearchResultSummaryAgent] Failed to encode payload.');
        }

        try {
            $method = new \ReflectionMethod(get_parent_class($this), 'respond');
            $arguments = [$payload];

            if ($method->getNumberOfParameters() >= 2) {
                $arguments[] = $this->structuredOutput();
            }

            $response = $method->invokeArgs($this, $arguments);

            if (is_array($response)) {
                return ['summary' => self::limitWords(trim((string) ($response['summary'] ?? '')))];
            }

            throw new RuntimeException('[SearchResultSummaryAgent] Unexpected response type from AI provider.');
        } catch (TypeError $exception) {
            throw new RuntimeException('AI provider returned invalid content: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function prompt($message): array|string
    {
        return $message;
    }

    public static function summarize(string $query, ?string $notes, string $chunkText): string
    {
        $query = trim($query);
        $chunkText = trim($chunkText);

        if ($query === '' || $chunkText === '') {
            return '';
        }

        try {
            $agent = new self('search-result-summary');
            $result = $agent->withInput($query, $notes, $chunkText)->respond();

            if (is_array($result)) {
                return self::limitWords(trim((string) ($result['summary'] ?? ''))) ?? '';
            }
        } catch (Throwable) {
            // Fall back to deterministic truncation below.
        }

        return self::limitWords($chunkText) ?? '';
    }

    private static function limitWords(string $text): ?string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($normalized === '') {
            return null;
        }

        $words = preg_split('/\s+/u', $normalized) ?: [];
        if ($words === []) {
            return null;
        }

        return implode(' ', array_slice($words, 0, self::MAX_SUMMARY_WORDS));
    }
}
