<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use InvalidArgumentException;
use RuntimeException;
use SimoneBianco\LaravelAiAgents\Agents\RotableAgent;
use TypeError;

class GenerateDocumentMetadataAgent extends RotableAgent
{
    protected $history = 'LarAgent\\Context\\Drivers\\InMemoryStorage';

    protected $model = 'gpt-4o-mini';

    protected string $documentContent = '';
    protected string $currentName = '';
    protected string $currentDescription = '';

    protected bool $generateName = true;
    protected bool $generateDescription = true;
    protected bool $generateClassicTags = true;
    protected bool $generateSemanticTags = true;
    protected bool $generateQuestions = true;
    protected array $availableTagTypes = [];
    protected array $availableClassicTagsByType = [];

    public function withDocumentContent(string $documentContent): self
    {
        $this->documentContent = trim($documentContent);

        return $this;
    }

    public function withCurrentName(string $currentName): self
    {
        $this->currentName = trim($currentName);

        return $this;
    }

    public function withCurrentDescription(string $currentDescription): self
    {
        $this->currentDescription = trim($currentDescription);

        return $this;
    }

    public function withGenerateName(bool $enabled = true): self
    {
        $this->generateName = $enabled;

        return $this;
    }

    public function withGenerateDescription(bool $enabled = true): self
    {
        $this->generateDescription = $enabled;

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

    public function structuredOutput(): array
    {
        return [
            'name' => 'generate_document_metadata',
            'strict' => true,
            'schema' => $this->getResponseSchema(),
        ];
    }

    protected function getResponseSchema(): array
    {
        $properties = [];
        $required = [];

        if ($this->generateName) {
            $properties['name'] = [
                'type' => 'string',
                'description' => 'A concise and specific document title.',
            ];
            $required[] = 'name';
        }

        if ($this->generateDescription) {
            $properties['description'] = [
                'type' => 'string',
                'description' => 'A concise, factual description of the document.',
            ];
            $required[] = 'description';
        }

        if ($this->generateClassicTags) {
            if (! empty($this->availableTagTypes)) {
                foreach ($this->availableTagTypes as $type) {
                    $field = "tags_{$type}";
                    $fieldSchema = [
                        'type' => 'array',
                        'description' => "Classic tags of type '{$type}'. Leave empty if none apply.",
                        'items' => [
                            'type' => 'string',
                        ],
                    ];

                    $allowedTagsByType = $this->availableClassicTagsByType[$type] ?? [];
                    if (! empty($allowedTagsByType)) {
                        $fieldSchema['items']['enum'] = $allowedTagsByType;
                    }

                    $properties[$field] = $fieldSchema;
                    $required[] = $field;
                }
            } else {
                $properties['classic_tags'] = [
                    'type' => 'array',
                    'description' => 'Classic tags grouped by type.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'description' => 'Classic tag group type.',
                            ],
                            'tags' => [
                                'type' => 'array',
                                'description' => 'Tags that belong to this type.',
                                'items' => [
                                    'type' => 'string',
                                ],
                            ],
                        ],
                        'required' => ['type', 'tags'],
                        'additionalProperties' => false,
                    ],
                ];
                $required[] = 'classic_tags';
            }
        }

        if ($this->generateSemanticTags) {
            $properties['semantic_tags'] = [
                'type' => 'array',
                'description' => 'Semantic retrieval tags.',
                'items' => [
                    'type' => 'string',
                ],
            ];
            $required[] = 'semantic_tags';
        }

        if ($this->generateQuestions) {
            $properties['questions'] = [
                'type' => 'array',
                'description' => 'Questions this document can answer.',
                'items' => [
                    'type' => 'string',
                ],
            ];
            $required[] = 'questions';
        }

        if (empty($properties)) {
            throw new InvalidArgumentException('[GenerateDocumentMetadataAgent] No metadata field selected');
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    public function instructions(): string
    {
        $name = $this->currentName !== '' ? $this->currentName : '(not set)';
        $description = $this->currentDescription !== '' ? $this->currentDescription : '(not set)';
        $classicTagsCatalog = $this->buildClassicTagsCatalogForPrompt();
        $availableTagTypes = $this->buildAvailableTagTypesForPrompt();

        return <<<PROMPT
You are an expert metadata strategist for retrieval-augmented knowledge bases.

Generate only the requested fields from the provided document content.

Current document metadata:
- name: {$name}
- description: {$description}

Requested fields:
- generate_name: {$this->toPromptBool($this->generateName)}
- generate_description: {$this->toPromptBool($this->generateDescription)}
- generate_classic_tags: {$this->toPromptBool($this->generateClassicTags)}
- generate_semantic_tags: {$this->toPromptBool($this->generateSemanticTags)}
- generate_questions: {$this->toPromptBool($this->generateQuestions)}

Classic tags catalog (type => allowed tag slugs):
{$classicTagsCatalog}

Available classic tag types:
{$availableTagTypes}

Rules:
- Use only facts and concepts present in the document content.
- Keep output compact, concrete, and deduplicated.
- For classic tags, use only available classic tag types.
- For each available type, fill the matching `tags_{type}` array using only allowed tag slugs from the classic tags catalog.
- If no value applies for a type, return an empty array for that specific `tags_{type}` field.
- For semantic_tags, use lowercase slug-case whenever possible.
- For questions, write specific and directly answerable questions.
- Return only schema-compliant structured output.
PROMPT;
    }

    public function respond(?string $message = null): array|\LarAgent\Core\Contracts\DataModel|\LarAgent\Core\Contracts\Message|string
    {
        $content = trim($message ?? $this->documentContent);
        if ($content === '') {
            throw new InvalidArgumentException('[GenerateDocumentMetadataAgent] Document content is required');
        }

        $payload = "Document content:\n\n{$content}";

        try {
            $method = new \ReflectionMethod(get_parent_class($this), 'respond');
            $arguments = [$payload];

            if ($method->getNumberOfParameters() >= 2) {
                $arguments[] = $this->structuredOutput();
            }

            $response = $method->invokeArgs($this, $arguments);

            if (is_array($response)) {
                return $this->normalizeMetadataResponse($response);
            }

            return $response;
        } catch (TypeError $e) {
            throw new RuntimeException('AI provider returned null content: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function toPromptBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    protected function buildClassicTagsCatalogForPrompt(): string
    {
        if (! $this->generateClassicTags) {
            return '(classic tags generation disabled)';
        }

        if (empty($this->availableClassicTagsByType)) {
            return '(no classic tag types available)';
        }

        return json_encode($this->availableClassicTagsByType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ?: '(invalid classic tags catalog)';
    }

    protected function buildAvailableTagTypesForPrompt(): string
    {
        if (! $this->generateClassicTags) {
            return '(classic tags generation disabled)';
        }

        if (empty($this->availableTagTypes)) {
            return '(no classic tag types available)';
        }

        return json_encode($this->availableTagTypes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ?: '(invalid classic tag types)';
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

            $normalizedType = trim((string) $type);
            if ($normalizedType === '') {
                continue;
            }

            $normalized[] = $normalizedType;
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
            $normalizedType = trim((string) $type);
            if ($normalizedType === '' || ! is_array($tags)) {
                continue;
            }

            $normalizedTags = [];
            foreach ($tags as $tag) {
                if (! is_string($tag) && ! is_numeric($tag)) {
                    continue;
                }

                $normalizedTag = trim((string) $tag);
                if ($normalizedTag === '') {
                    continue;
                }

                $normalizedTags[] = $normalizedTag;
            }

            $normalizedTags = array_values(array_unique($normalizedTags));
            if (empty($normalizedTags)) {
                continue;
            }

            $normalized[$normalizedType] = $normalizedTags;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    protected function normalizeMetadataResponse(array $response): array
    {
        if (! $this->generateClassicTags || empty($this->availableTagTypes)) {
            return $response;
        }

        $classicTags = [];

        foreach ($this->availableTagTypes as $type) {
            $field = "tags_{$type}";
            $tags = $this->normalizeFlatTagList(is_array($response[$field] ?? null) ? $response[$field] : []);
            unset($response[$field]);

            if (empty($tags)) {
                continue;
            }

            $classicTags[] = [
                'type' => $type,
                'tags' => $tags,
            ];
        }

        $response['classic_tags'] = $classicTags;

        return $response;
    }

    /**
     * @param array<int, mixed> $tags
     * @return array<int, string>
     */
    protected function normalizeFlatTagList(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            if (! is_string($tag) && ! is_numeric($tag)) {
                continue;
            }

            $normalizedTag = trim((string) $tag);
            if ($normalizedTag === '') {
                continue;
            }

            $normalized[] = $normalizedTag;
        }

        return array_values(array_unique($normalized));
    }

    public function prompt($message): array|string
    {
        return $message;
    }
}
