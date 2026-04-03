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
                    'description' => 'Concise description of the image for RAG retrieval. Cover key elements, text, labels, and relationships. Be specific but brief — 2 to 4 sentences max. Do NOT start with "The image shows" or "This depicts" — begin immediately with the content.',
                ],
                'tags' => [
                    'type'        => 'array',
                    'description' => 'List of 3 to 6 semantic tags for RAG retrieval. Must be LOWERCASE and SLUG_CASE (e.g., combat-map, skill-tree). Tag the main subject and key concepts.',
                    'items'       => ['type' => 'string'],
                ],
                'questions' => [
                    'type'        => 'array',
                    'description' => 'List of 2 to 3 questions this image answers. Each must explicitly name the subject — no pronouns.',
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
Image analyst for RAG systems. Generate concise, precise metadata for semantic retrieval.
$contextBlock$existingBlock
### OUTPUT
1. **content** — 2–4 sentences: key elements, visible text/labels, main subject. No filler, no preamble.
2. **tags** — 3–6 lowercase slug-case tags (e.g. combat-map, skill-tree).
3. **questions** — 2–3 questions this image answers. Name the subject explicitly — no pronouns.
4. **Deterministic tags** — Pick only from the provided enum values. Empty array if none apply.

### RULES
- `content` starts immediately with the subject — no "The image shows…" or "This depicts…"
- Be specific but brief: proper nouns, key numbers, labels as visible
- Questions must name the subject; never use "it", "they", "this"
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
