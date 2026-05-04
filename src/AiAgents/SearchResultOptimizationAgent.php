<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use InvalidArgumentException;
use RuntimeException;
use SimoneBianco\LaravelAiAgents\Agents\RotableAgent;
use Throwable;
use TypeError;

class SearchResultOptimizationAgent extends RotableAgent
{
    protected $history = 'LarAgent\\Context\\Drivers\\InMemoryStorage';

    protected $model = 'gpt-5.4-mini';

    protected $maxCompletionTokens = 8192;

    protected string $query = '';

    protected ?string $notes = null;

    protected ?string $existingSummary = null;

    protected string $chunkCatalog = '';

    protected bool $forceSplit = false;

    protected int $maxSummaryWords = 1000;

    public function withInput(string $query, ?string $notes, ?string $existingSummary, string $chunkCatalog, bool $forceSplit, int $maxSummaryWords = 1000): self
    {
        $this->query = trim($query);
        $this->notes = is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
        $this->existingSummary = is_string($existingSummary) && trim($existingSummary) !== '' ? trim($existingSummary) : null;
        $this->chunkCatalog = trim($chunkCatalog);
        $this->forceSplit = $forceSplit;
        $this->maxSummaryWords = self::normalizeMaxWords($maxSummaryWords);

        return $this;
    }

    public function structuredOutput(): array
    {
        return [
            'name' => 'search_result_optimization',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['keep', 'compact', 'split'],
                        'description' => 'Optimization decision for the saved memory.',
                    ],
                    'summary' => [
                        'type' => 'string',
                        'description' => 'Dense integrated summary when action is compact; empty otherwise.',
                    ],
                    'groups' => [
                        'type' => 'array',
                        'description' => 'Focused child memories when action is split.',
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
                'required' => ['action', 'summary', 'groups'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function instructions(): string
    {
        return <<<PROMPT
You optimize one saved RAG search-result memory.

INPUT
- query: reusable memory title/query.
- notes: optional ultra-short memory notes.
- existing_summary: optional previous compact summary that must be integrated if compacting.
- force_split: when true, action MUST be split.
- chunk_catalog: every backing chunk with id, document, chapter, and content preview.

TASK
Choose exactly one action:
- keep: chunks are already focused and not too broad.
- compact: chunks cover one coherent topic; write one dense summary using only facts relevant to query/notes.
- split: chunks contain multiple distinct topics/actors/angles; create 2 to 6 focused groups.

RULES
1. If force_split is true, return action=split and never compact.
2. Ignore chunk material unrelated to query and notes.
3. Split when the memory mixes separable entities or questions, e.g. "who are Giacomo and Fabrizio" -> one group per person.
4. Compact when chunks are facets of one subject, e.g. "Giacomo appearance, habits, work" -> one summary.
5. Summary max {$this->maxSummaryWords} words. Do not be terse: include all relevant facts, names, dates, places, events, numbers, causal links from the chunks that relate to the query.
6. Split groups must use only chunk IDs from chunk_catalog; each group needs at least one chunk_id.
7. Notes are ultra-short keyword fragments, max 30 words.
8. Do not invent facts or topics. Return schema-compliant JSON only.
PROMPT;
    }

    public function respond(?string $message = null): array|string
    {
        if ($this->query === '' || $this->chunkCatalog === '') {
            throw new InvalidArgumentException('[SearchResultOptimizationAgent] query and chunk catalog are required.');
        }

        $payload = json_encode([
            'query' => $this->query,
            'notes' => $this->notes,
            'existing_summary' => $this->existingSummary,
            'force_split' => $this->forceSplit,
            'chunk_catalog' => $this->chunkCatalog,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($payload) || $payload === '') {
            throw new RuntimeException('[SearchResultOptimizationAgent] Failed to encode payload.');
        }

        try {
            $method = new \ReflectionMethod(get_parent_class($this), 'respond');
            $arguments = [$payload];

            if ($method->getNumberOfParameters() >= 2) {
                $arguments[] = $this->structuredOutput();
            }

            $response = $method->invokeArgs($this, $arguments);

            if (is_array($response)) {
                return $response;
            }

            throw new RuntimeException('[SearchResultOptimizationAgent] Unexpected response type from AI provider.');
        } catch (TypeError $exception) {
            throw new RuntimeException('AI provider returned invalid content: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function prompt($message): array|string
    {
        return $message;
    }

    /** @return array{action: string, summary: ?string, groups: array<int, array{query: string, notes: ?string, chunk_ids: array<int, string>}>} */
    public static function optimize(string $query, ?string $notes, ?string $existingSummary, string $chunkCatalog, bool $forceSplit = false, ?int $maxSummaryWords = 1000): array
    {
        $maxSummaryWords = self::normalizeMaxWords($maxSummaryWords ?? 1000);

        try {
            $agent = new self('search-result-optimization');
            $result = $agent->withInput($query, $notes, $existingSummary, $chunkCatalog, $forceSplit, $maxSummaryWords)->respond();

            if (! is_array($result)) {
                return self::fallback($forceSplit);
            }

            $action = is_string($result['action'] ?? null) ? trim($result['action']) : 'keep';
            if (! in_array($action, ['keep', 'compact', 'split'], true)) {
                $action = 'keep';
            }

            if ($forceSplit) {
                $action = 'split';
            }

            $summary = is_string($result['summary'] ?? null) && trim($result['summary']) !== ''
                ? self::limitWords(trim($result['summary']), $maxSummaryWords)
                : null;

            return [
                'action' => $action,
                'summary' => $summary,
                'groups' => self::normalizeGroups(is_array($result['groups'] ?? null) ? $result['groups'] : []),
            ];
        } catch (Throwable) {
            return self::fallback($forceSplit);
        }
    }

    /** @return array{action: string, summary: ?string, groups: array<int, array{query: string, notes: ?string, chunk_ids: array<int, string>}>} */
    private static function fallback(bool $forceSplit): array
    {
        return ['action' => $forceSplit ? 'split' : 'keep', 'summary' => null, 'groups' => []];
    }

    /** @param array<int, mixed> $groups @return array<int, array{query: string, notes: ?string, chunk_ids: array<int, string>}> */
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

        return array_values(array_slice($normalized, 0, 6));
    }

    private static function limitWords(string $text, int $maxWords): ?string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($normalized === '') {
            return null;
        }

        $words = preg_split('/\s+/u', $normalized) ?: [];

        return implode(' ', array_slice($words, 0, self::normalizeMaxWords($maxWords)));
    }

    private static function normalizeMaxWords(int $maxWords): int
    {
        return max(100, min(5000, $maxWords));
    }
}
