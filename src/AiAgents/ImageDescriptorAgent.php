<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use RuntimeException;
use TypeError;

class ImageDescriptorAgent extends RotableAgent
{
    protected $history = InMemoryStorage::class;

    protected array $config = [];
    protected string $imageDataUrl = '';
    protected string $additionalContext = '';
    protected array $tagsByType = [];
    protected string $existingContent = '';
    protected array $existingTags = [];
    protected array $existingQuestions = [];

    public function __construct(string $key, array $injectConfig = [])
    {
        parent::__construct($key);

        $config = config('rag_chunks.agents.image_descriptor', []);
        $this->config['provider'] = $injectConfig['provider'] ?? $config['provider'] ?? 'default';
        $this->config['model']    = $injectConfig['model']    ?? $config['model']    ?? 'gpt-4.1-mini';
    }

    public function withImageDataUrl(string $imageDataUrl): self
    {
        $this->imageDataUrl = $imageDataUrl;
        return $this;
    }

    public function withContext(string $context): self
    {
        $this->additionalContext = $context;
        return $this;
    }

    public function withTagsByType(array $tagsByType): self
    {
        $this->tagsByType = $tagsByType;
        return $this;
    }

    public function withExistingContext(string $existingContent, array $existingTags, array $existingQuestions): self
    {
        $this->existingContent   = $existingContent;
        $this->existingTags      = $existingTags;
        $this->existingQuestions = $existingQuestions;
        return $this;
    }

    public function structuredOutput(): array
    {
        return $this->getResponseSchema();
    }

    protected function getResponseSchema(): array
    {
        $deterministicTagProperties = [];
        $deterministicTagRequired   = [];

        foreach ($this->tagsByType as $type => $tags) {
            $deterministicTagProperties["tags_{$type}"] = [
                'type'        => 'array',
                'description' => "Deterministic tags of type '{$type}'. MUST pick ONLY from the provided slug enum values (kebab-case identifiers). Leave empty if none apply.",
                'items'       => [
                    'type' => 'string',
                    'enum' => $tags,
                ],
            ];
            $deterministicTagRequired[] = "tags_{$type}";
        }

        return [
            'type'       => 'object',
            'properties' => [
                'content' => [
                    'type'        => 'string',
                    'description' => 'A thorough, detailed textual description of the image for RAG retrieval. Describe all visible elements, text, labels, data, diagrams, and relationships. Be specific and exhaustive. Do NOT start with phrases like "The image shows" or "This image depicts" — begin immediately with the content.',
                ],
                'tags' => [
                    'type'        => 'array',
                    'description' => 'List of 5 to 10 semantic tags for RAG retrieval. Must be strictly LOWERCASE and SLUG_CASE (e.g., combat-map, skill-tree). Tag the main subject, all depicted entities, and key concepts. The primary subject MUST be included.',
                    'items'       => ['type' => 'string'],
                ],
                'questions' => [
                    'type'        => 'array',
                    'description' => 'List of 3 to 5 reverse-engineered questions that this image answers perfectly. CRITICAL: every question MUST explicitly name the main subject or entity depicted — never use pronouns like "it" or "they".',
                    'items'       => ['type' => 'string'],
                ],
                ...$deterministicTagProperties,
            ],
            'required'             => ['content', 'tags', 'questions', ...$deterministicTagRequired],
            'additionalProperties' => false,
        ];
    }

    public function instructions(): string
    {
        $contextBlock = !empty($this->additionalContext)
            ? "\n### DOCUMENT CONTEXT\n{$this->additionalContext}\n"
            : '';

        $existingBlock = '';
        if (!empty($this->existingContent) || !empty($this->existingTags) || !empty($this->existingQuestions)) {
            $parts = [];
            if (!empty($this->existingContent)) {
                $parts[] = "Content: {$this->existingContent}";
            }
            if (!empty($this->existingTags)) {
                $parts[] = 'Tags: ' . implode(', ', $this->existingTags);
            }
            if (!empty($this->existingQuestions)) {
                $parts[] = "Questions:\n- " . implode("\n- ", $this->existingQuestions);
            }
            $existingBlock = "\n### EXISTING METADATA (refine, don't discard)\n" . implode("\n", $parts) . "\n";
        }

        return <<<INSTRUCTIONS
### ROLE
Expert image analyst for RAG systems. Generate dense, precise metadata for semantic retrieval.
$contextBlock$existingBlock
### OUTPUT
1. **content** — Exhaustive description: all visible text, numbers, labels, diagrams, relationships, entities. No filler, no preamble. Every sentence must convey concrete information from the image.
2. **tags** — 5–10 lowercase slug-case tags (e.g. combat-map, skill-tree). Cover subject, entities, actions, key concepts.
3. **questions** — 3–5 questions this image answers perfectly. Each must name the subject explicitly — no pronouns.
4. **Deterministic tags** — Pick only from the provided enum values. Empty array if none apply.

### RULES
- `content` starts immediately with the subject — no "The image shows…" or "This depicts…"
- Be specific: exact proper nouns, numbers, labels, technical terms as visible
- Tags and questions stay out of `content`
- DONT omit relevant information, BUT be as short as possible
- Questions must name the subject; never use "it", "they", "this", or "the depicted"
INSTRUCTIONS;
    }

    public function respond(?string $message = null): string|array|DataModel|MessageInterface
    {
        $this->changeProvider($this->config['provider']);
        $this->model = $this->config['model'];

        if (!empty($this->imageDataUrl)) {
            $this->withImages([$this->imageDataUrl]);
        }

        try {
            $response = parent::respond('Analyze this image and generate the required metadata.');
        } catch (TypeError $e) {
            throw new RuntimeException('AI provider returned null content: ' . $e->getMessage(), 0, $e);
        }

        return $response;
    }

    public function prompt($message)
    {
        return $message;
    }
}
