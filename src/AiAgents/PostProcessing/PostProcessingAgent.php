<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing;

use LarAgent\Agent;
use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use RuntimeException;
use TypeError;

class PostProcessingAgent extends Agent
{
    protected $history = CacheStorage::class;
    protected array $chunks = [];
    protected $mcpServers = [];
    protected string $documentContext = '';
    protected array $config = [];
    protected int $preferredChunkLength = 600;

    public function __construct(string $key, array $injectConfig = [])
    {
        parent::__construct($key);

        $config = config('rag_chunks.agents.postprocessor', []);
        $this->config['provider'] = $injectConfig['provider'] ?? $config['provider'] ?? 'openai';
        $this->config['model'] = $injectConfig['model'] ?? $config['model'] ?? 'gpt-4o';
        $this->preferredChunkLength = $injectConfig['preferred_chunk_length'] ?? $config['preferred_chunk_length'] ?? 600;
    }

    public function withChunks(array $chunks): self
    {
        $this->chunks = $chunks;
        return $this;
    }

    public function withPreferredChunkLength(int $length): self
    {
        $this->preferredChunkLength = $length;
        return $this;
    }

    protected function getResponseSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'List of dynamically sized, ordered chunks with questions and tags',
            'properties' => [
                'chunks' => [
                    'type' => 'array',
                    'description' => 'Dynamically processed chunks, forming highly cohesive atomic semantic units.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => [
                                'type' => 'string',
                                'description' => 'The raw, unsummarized text of the chunk. You MUST strictly preserve all newlines (\n), tabs, formatting, numerical data, and stat blocks. NEVER summarize. STRICTLY FORBIDDEN to include words like "Tags:" or "Questions:" inside this field. May include a brief injected context at the very beginning if the data is highly abstract.'
                            ],
                            'tags' => [
                                'type' => 'array',
                                'description' => 'List of 5 to 10 semantic tags for RAG retrieval. CRITICAL: The main subject/entity of the chunk MUST be included as a tag (e.g., "aboleth"). Must be strictly LOWERCASE and SLUG_CASE (e.g., "aboleth", "lair_actions"). Tag both the subject and the action/event. Do not generate fewer than 5 tags.',
                                'items' => [
                                    'type' => 'string'
                                ]
                            ],
                            'questions' => [
                                'type' => 'array',
                                'description' => 'List of 3 to 5 reverse-engineered questions that this specific chunk answers perfectly. CRITICAL: Every single question MUST explicitly include the subject or entity name of the chunk (e.g., write "What is the Armor Class of an Aboleth?", NEVER write "What is its Armor Class?"). Do not generate fewer than 3 questions.',
                                'items' => [
                                    'type' => 'string'
                                ]
                            ],
                            'figure_path' => [
                                'type' => 'string',
                                'description' => 'The path to the figure/image. Return an empty string "" if there is no relevant figure.'
                            ],
                        ],
                        'required' => ['content', 'tags', 'questions', 'figure_path'],
                        'additionalProperties' => false
                    ]
                ],
            ],
            'required' => ['chunks'],
            'additionalProperties' => false
        ];
    }

    public function structuredOutput(): array
    {
        return $this->getResponseSchema();
    }

    public function withDocumentContext(?string $context): self
    {
        if (!empty($context)) {
            $this->documentContext = "\n### DOCUMENT CONTEXT\n$context";
        }
        return $this;
    }

    public function instructions(): string
    {
        return <<<INSTRUCTIONS
### PERSONA
You are an expert optimizer and dynamic chunker for RAG systems.

$this->documentContext

### GOAL & DYNAMIC CHUNKING
Analyze the provided raw chunks. Clean, merge, or split them to form highly cohesive, atomic semantic units.
- You MUST NOT output a 1:1 mapping of input to output.
- Separate distinct topics (e.g., isolate a lore description from a stat block).
- Target Size: ~$this->preferredChunkLength characters (SOFT limit). Semantic integrity (keeping a stat block together) ALWAYS overrides size.

### CRITICAL CONTENT RULES
1. **ZERO SUMMARIZATION**: Preserve all sentences, numerical data, stats, and symbols EXACTLY as written.
2. **PRESERVE FORMATTING**: Do NOT remove newlines (`\n`), tabs, or spacing from stat blocks, tables, or item descriptions. The rigid structure must remain intact.
3. **NO METADATA IN TEXT**: Put tags and questions EXCLUSIVELY in their dedicated JSON arrays. Never print them inside the `content` string.
4. **SUBJECT IN METADATA**: The tags array AND every single question MUST explicitly include the main subject/entity name of the chunk. Never use pronouns like "it" or "they" in questions.
5. **MINIMUM METADATA**: You MUST generate at least 5 tags and at least 3 questions per output chunk. Do not be lazy.
6. **Context Injection**: For highly abstract data (e.g., an isolated stat table), prepend a brief clarifying context to the `content`.

### FIGURE RULES
Preserve `figure_path` if present. If merging chunks with different figures, keep the most relevant or split the chunks to preserve both. Return "" if no figure.
INSTRUCTIONS;
    }

    public function respond(?string $message = null): string|array|DataModel|MessageInterface
    {
        $this->changeProvider($this->config['provider']);
        $this->model = $this->config['model'];

        try {
            $response = parent::respond("Raw Input Chunks to Process:\n\n" . json_encode($this->chunks));
        } catch (TypeError $e) {
            throw new RuntimeException('AI provider returned null content (transient error or refusal): ' . $e->getMessage(), 0, $e);
        }

        return $response['chunks'] ?? [];
    }

    public function prompt($message)
    {
        return $message;
    }
}
