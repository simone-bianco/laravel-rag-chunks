<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use InvalidArgumentException;
use RuntimeException;
use SimoneBianco\LaravelAiAgents\Agents\RotableAgent;
use Throwable;
use TypeError;

/**
 * Reduces memory notes to ultra-short caveman style keywords.
 */
class MemoryNoteReducerAgent extends RotableAgent
{
    private const MAX_NOTE_WORDS = 30;

    protected $history = 'LarAgent\\Context\\Drivers\\InMemoryStorage';

    protected $model = 'gpt-4o-mini';

    protected string $query = '';

    protected ?string $notes = null;

    public function withInput(string $query, ?string $notes): self
    {
        $this->query = trim($query);
        $this->notes = is_string($notes) && trim($notes) !== '' ? trim($notes) : null;

        return $this;
    }

    public function structuredOutput(): array
    {
        return [
            'name' => 'reduce_memory_note',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'notes' => [
                        'type' => 'string',
                        'description' => 'Ultra-short caveman note (max 30 words), keywords only.',
                    ],
                ],
                'required' => ['notes'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
You reduce search memory notes.

Rules:
1. Output ONLY one ultra-short note.
2. Style MUST be caveman: keyword fragments, no prose.
3. Max 30 words.
4. Keep only core coverage topics from query/notes.
5. No markdown, no bullets, no labels.
6. Never invent facts.
7. If notes is empty, derive keywords from query.
PROMPT;
    }

    public function respond(?string $message = null): array|string
    {
        if ($this->query === '') {
            throw new InvalidArgumentException('[MemoryNoteReducerAgent] Missing query.');
        }

        $payload = json_encode([
            'query' => $this->query,
            'notes' => $this->notes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($payload) || $payload === '') {
            throw new RuntimeException('[MemoryNoteReducerAgent] Failed to encode payload.');
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
                    'notes' => self::limitWords(trim((string) ($response['notes'] ?? ''))),
                ];
            }

            throw new RuntimeException('[MemoryNoteReducerAgent] Unexpected response type from AI provider.');
        } catch (TypeError $exception) {
            throw new RuntimeException('AI provider returned invalid content: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function prompt($message): array|string
    {
        return $message;
    }

    public static function reduce(string $query, ?string $notes): ?string
    {
        $query = trim($query);
        $notes = is_string($notes) && trim($notes) !== '' ? trim($notes) : null;

        if ($query === '' && $notes === null) {
            return null;
        }

        try {
            $agent = new self('memory-note-reducer');
            $result = $agent->withInput($query !== '' ? $query : (string) $notes, $notes)->respond();

            if (is_array($result)) {
                $reduced = trim((string) ($result['notes'] ?? ''));
                if ($reduced !== '') {
                    return self::limitWords($reduced);
                }
            }
        } catch (Throwable) {
            // Fallback below
        }

        $fallback = $notes ?? $query;

        return $fallback !== '' ? self::limitWords($fallback) : null;
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

        return implode(' ', array_slice($words, 0, self::MAX_NOTE_WORDS));
    }
}
