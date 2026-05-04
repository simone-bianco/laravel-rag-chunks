<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use InvalidArgumentException;
use RuntimeException;
use SimoneBianco\LaravelAiAgents\Agents\RotableAgent;
use Throwable;
use TypeError;

class SearchResultSummaryAgent extends RotableAgent
{
    protected $history = 'LarAgent\\Context\\Drivers\\InMemoryStorage';

    protected $model = 'gpt-5.4-mini';

    protected $maxCompletionTokens = 4096;

    protected string $query = '';

    protected ?string $notes = null;

    protected string $chunkText = '';

    protected int $maxSummaryWords = 1000;

    public function withInput(string $query, ?string $notes, string $chunkText): self
    {
        $this->query = trim($query);
        $this->notes = is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
        $this->chunkText = trim($chunkText);

        return $this;
    }

    public function maxSummaryWords(int $maxSummaryWords): self
    {
        $this->maxSummaryWords = self::normalizeMaxWords($maxSummaryWords);

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
        return <<<PROMPT
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
6. Max {$this->maxSummaryWords} words. Do not be terse: include all relevant facts, names, places, events, and causal links.
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
                return ['summary' => self::limitWords(trim((string) ($response['summary'] ?? '')), $this->maxSummaryWords)];
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

    public static function summarize(string $query, ?string $notes, string $chunkText, ?int $maxWords = 1000): string
    {
        $query = trim($query);
        $chunkText = trim($chunkText);
        $maxWords = self::normalizeMaxWords($maxWords ?? 1000);

        if ($query === '' || $chunkText === '') {
            return '';
        }

        try {
            $agent = new self('search-result-summary');
            $result = $agent
                ->maxSummaryWords($maxWords)
                ->withInput($query, $notes, $chunkText)
                ->respond();

            if (is_array($result)) {
                return self::limitWords(trim((string) ($result['summary'] ?? '')), $maxWords) ?? '';
            }
        } catch (Throwable) {
            // Fall back to deterministic truncation below.
        }

        return self::limitWords($chunkText, $maxWords) ?? '';
    }

    private static function limitWords(string $text, int $maxWords): ?string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($normalized === '') {
            return null;
        }

        $words = preg_split('/\s+/u', $normalized) ?: [];
        if ($words === []) {
            return null;
        }

        return implode(' ', array_slice($words, 0, self::normalizeMaxWords($maxWords)));
    }

    private static function normalizeMaxWords(int $maxWords): int
    {
        return max(100, min(5000, $maxWords));
    }
}
