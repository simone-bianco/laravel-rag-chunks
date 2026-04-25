<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use InvalidArgumentException;
use RuntimeException;
use SimoneBianco\LaravelAiAgents\Agents\RotableAgent;
use TypeError;

class RecomputeChunkMetadataAgent extends RotableAgent
{
    protected $history = 'LarAgent\\Context\\Drivers\\InMemoryStorage';

    protected $model = 'gpt-5-mini';

    protected array $chunks = [];
    protected bool $generateClassicTags = true;
    protected bool $generateSemanticTags = true;
    protected bool $generateQuestions = true;
    protected array $availableTagTypes = [];
    protected array $availableClassicTagsByType = [];
    protected string $additionalInstructions = '';
    protected array $documentContext = [];

    public function withChunks(array $chunks): self
    {
        $this->chunks = $chunks;

        return $this;
    }

    public function withGenerateClassicTags(bool $enabled = true): self
    {
        $this->generateClassicTags = $enabled;

        return $this;
    }

    public function withGenerateSemanticTags(bool $enabled = true): self
    {
        $this->generateSemanticTags = $enabled;

        return $this;
    }

    public function withGenerateQuestions(bool $enabled = true): self
    {
        $this->generateQuestions = $enabled;

        return $this;
    }

    /**
     * @param array<int, string> $types
     */
    public function withAvailableTagTypes(array $types): self
    {
        $this->availableTagTypes = $this->normalizeTagTypes($types);

        return $this;
    }

    /**
     * @param array<string, array<int, string>> $tagsByType
     */
    public function withAvailableClassicTagsByType(array $tagsByType): self
    {
        $this->availableClassicTagsByType = $this->normalizeClassicTagsByType($tagsByType);
        $this->availableTagTypes = array_values(array_unique([
            ...$this->availableTagTypes,
            ...array_keys($this->availableClassicTagsByType),
        ]));

        return $this;
    }

    public function withAdditionalInstructions(string $instructions = ''): self
    {
        $this->additionalInstructions = trim($instructions);

        return $this;
    }

    /**
     * @param array<string, mixed> $documentContext
     */
    public function withDocumentContext(array $documentContext): self
    {
        $normalized = [];

        $title = trim((string) ($documentContext['title'] ?? ''));
        if ($title !== '') {
            $normalized['title'] = $title;
        }

        $description = trim((string) ($documentContext['description'] ?? ''));
        if ($description !== '') {
            $normalized['description'] = $description;
        }

        $metadata = $documentContext['metadata'] ?? null;
        if (is_array($metadata) && ! empty($metadata)) {
            $normalized['metadata'] = $metadata;
        }

        $this->documentContext = $normalized;

        return $this;
    }

    public function structuredOutput(): array
    {
        return [
            'name' => 'recompute_chunk_metadata',
            'strict' => true,
            'schema' => $this->getResponseSchema(),
        ];
    }

    protected function getResponseSchema(): array
    {
        $itemProperties = [
            'chunk_id' => [
                'type' => 'string',
                'description' => 'Chunk UUID from input.',
            ],
        ];
        $itemRequired = ['chunk_id'];

        if ($this->generateClassicTags) {
            $itemProperties['classic_tags'] = [
                'type' => 'array',
                'description' => 'Classic tags grouped by type alias.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['type', 'tags'],
                    'additionalProperties' => false,
                ],
            ];
            $itemRequired[] = 'classic_tags';
        }

        if ($this->generateSemanticTags) {
            $itemProperties['semantic_tags'] = [
                'type' => 'array',
                'description' => 'Semantic tags for retrieval.',
                'items' => ['type' => 'string'],
            ];
            $itemRequired[] = 'semantic_tags';
        }

        if ($this->generateQuestions) {
            $itemProperties['questions'] = [
                'type' => 'array',
                'description' => 'Questions this chunk can answer.',
                'items' => ['type' => 'string'],
            ];
            $itemRequired[] = 'questions';
        }

        return [
            'type' => 'object',
            'properties' => [
                'chunks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => $itemProperties,
                        'required' => $itemRequired,
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['chunks'],
            'additionalProperties' => false,
        ];
    }

    public function instructions(): string
    {
        $availableCatalog = json_encode($this->availableClassicTagsByType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $typesList = json_encode($this->availableTagTypes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        $documentContext = json_encode($this->documentContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $extra = $this->additionalInstructions !== ''
            ? "\nAdditional instructions:\n{$this->additionalInstructions}\n"
            : '';

        return <<<PROMPT
You are a chunk metadata recomputation expert.

Your task: recompute metadata for each chunk in input.

Requested outputs:
- generate_classic_tags: {$this->toPromptBool($this->generateClassicTags)}
- generate_semantic_tags: {$this->toPromptBool($this->generateSemanticTags)}
- generate_questions: {$this->toPromptBool($this->generateQuestions)}

Available classic tag types:
{$typesList}

Available classic tags catalog (type => allowed slugs):
{$availableCatalog}

Document context (if present):
{$documentContext}
{$extra}
Rules:
1. Preserve fidelity to each chunk content. Do not invent facts.
2. You receive current metadata values in input; use them as context to improve consistency.
3. Return exactly one output object for each input chunk (same chunk_id).
4. For classic_tags, use only allowed type aliases and allowed tag slugs from catalog.
5. Keep output concise, deduplicated, schema-compliant.
PROMPT;
    }

    public function respond(?string $message = null): array|string
    {
        if (empty($this->chunks)) {
            throw new InvalidArgumentException('[RecomputeChunkMetadataAgent] Chunks are required');
        }

        $payloadData = ['chunks' => $this->chunks];
        if (! empty($this->documentContext)) {
            $payloadData['document'] = $this->documentContext;
        }

        $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($payload) || $payload === '') {
            throw new RuntimeException('[RecomputeChunkMetadataAgent] Failed to encode payload');
        }

        try {
            $method = new \ReflectionMethod(get_parent_class($this), 'respond');
            $arguments = [$payload];

            if ($method->getNumberOfParameters() >= 2) {
                $arguments[] = $this->structuredOutput();
            }

            $response = $method->invokeArgs($this, $arguments);

            return is_array($response) ? $response : [];
        } catch (TypeError $exception) {
            throw new RuntimeException('AI provider returned invalid content: ' . $exception->getMessage(), 0, $exception);
        }
    }

    protected function toPromptBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * @param array<int, string> $types
     * @return array<int, string>
     */
    protected function normalizeTagTypes(array $types): array
    {
        $normalized = [];

        foreach ($types as $type) {
            if (! is_string($type) && ! is_numeric($type)) {
                continue;
            }

            $item = trim((string) $type);
            if ($item === '') {
                continue;
            }

            $normalized[] = $item;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, array<int, string>> $tagsByType
     * @return array<string, array<int, string>>
     */
    protected function normalizeClassicTagsByType(array $tagsByType): array
    {
        $normalized = [];

        foreach ($tagsByType as $type => $tags) {
            $typeAlias = trim((string) $type);
            if ($typeAlias === '' || ! is_array($tags)) {
                continue;
            }

            $normalizedTags = [];
            foreach ($tags as $tag) {
                if (! is_string($tag) && ! is_numeric($tag)) {
                    continue;
                }

                $tagSlug = trim((string) $tag);
                if ($tagSlug === '') {
                    continue;
                }

                $normalizedTags[] = $tagSlug;
            }

            $normalized[$typeAlias] = array_values(array_unique($normalizedTags));
        }

        return $normalized;
    }

    public function prompt($message): array|string
    {
        return $message;
    }
}
