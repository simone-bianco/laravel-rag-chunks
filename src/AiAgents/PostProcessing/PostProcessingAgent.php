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
    protected bool $contextInjection = false;
    protected bool $summarization = false;
    protected string $extraInstruction = '';

    public function __construct(string $key, array $injectConfig = [])
    {
        parent::__construct($key);

        $config = config('rag_chunks.agents.postprocessor', []);
        $this->config['provider'] = $injectConfig['provider'] ?? $config['provider'] ?? 'openai';
        $this->config['model'] = $injectConfig['model'] ?? $config['model'] ?? 'gpt-5-mini';
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

    public function withSummarization(bool $summarization = false): self
    {
        $this->summarization = $summarization;
        return $this;
    }

    public function withContextInjection(bool $contextInjection = true): self
    {
        $this->contextInjection = $contextInjection;
        return $this;
    }

    public function withExtraInstructions(?string $extraInstructions = ''): self
    {
        $this->extraInstruction = $extraInstructions ?: '';
        return $this;
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

    protected function getResponseSchema(): array
    {
        if ($this->summarization) {
            $contentDescription = 'The condensed, summarized text of the chunk. Extract the core semantic meaning. You MUST still preserve crucial numerical data and stat blocks exactly. STRICTLY FORBIDDEN to include words like "Tags:" or "Questions:" inside this field.';
        } else {
            $contentDescription = 'The raw, unsummarized text of the chunk. You MUST strictly preserve all newlines (\n), tabs, formatting, numerical data, and stat blocks. NEVER summarize. STRICTLY FORBIDDEN to include words like "Tags:" or "Questions:" inside this field.';
        }

        if ($this->contextInjection) {
            $contentDescription .= ' May include a brief injected context at the very beginning if the data is highly abstract.';
        } else {
            $contentDescription .= ' STRICTLY FORBIDDEN to include meta-commentary like "These chunks describe...", "Here is...", or "This text covers...". Start IMMEDIATELY with the source text.';
        }

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
                                'description' => $contentDescription
                            ],
                            'tags' => [
                                'type' => 'array',
                                'description' => 'List of 5 to 10 semantic tags for RAG retrieval. CRITICAL: The main subject/entity of the chunk MUST be included as a tag (e.g., "aboleth"). Must be strictly LOWERCASE and SLUG_CASE (e.g., "aboleth", "lair_actions"). Tag both the subject and the action/event. Do not generate fewer than 5 tags.',
                                'items' => ['type' => 'string']
                            ],
                            'questions' => [
                                'type' => 'array',
                                'description' => 'List of 3 to 5 reverse-engineered questions that this specific chunk answers perfectly. CRITICAL: Every single question MUST explicitly include the subject or entity name of the chunk. Do not generate fewer than 3 questions.',
                                'items' => ['type' => 'string']
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

    public function instructions(): string
    {
        // Regola dinamica per la Context Injection
        $contextRule = $this->contextInjection
            ? "6. **CONTEXT INJECTION**: For highly abstract data (e.g., an isolated stat table), prepend a brief clarifying context to the `content`."
            : "6. **NO META-COMMENTARY (CRITICAL)**: NEVER start the `content` with conversational phrases like \"These chunks describe...\". Start immediately with the raw text.";

        // Regola dinamica per la Summarization
        $summarizationRule = $this->summarization
            ? "1. **SUMMARIZE CONTENT**: Condense the text to its core semantic meaning to save space. Discard fluff. However, you MUST STILL preserve numerical data, stats, and symbols EXACTLY."
            : "1. **ZERO SUMMARIZATION**: Preserve all sentences, numerical data, stats, and symbols EXACTLY as written.";

        // Blocco opzionale per le Extra Instructions
        $extraInstructionsBlock = !empty($this->extraInstruction)
            ? "\n### EXTRA INSTRUCTIONS\n{$this->extraInstruction}\n"
            : "";

        // Calcoliamo una stima in parole
        $estimatedWords = (int)($this->preferredChunkLength / 6);

        return <<<INSTRUCTIONS
### PERSONA
You are an expert optimizer and dynamic chunker for RAG systems.

$this->documentContext

### GOAL & DYNAMIC CHUNKING
Analyze the provided raw chunks. Clean, merge, or split them to form highly cohesive, atomic semantic units.
- You MUST NOT output a 1:1 mapping of input to output.
- Separate distinct topics (e.g., isolate a lore description from a stat block).
- TARGET SIZE: Maximum $this->preferredChunkLength characters (approximately $estimatedWords words) per chunk.
- AGGRESSIVE SPLITTING: If a combined topic exceeds this limit, you MUST split it into smaller, logical sub-chunks. Semantic integrity (keeping a stat table together) overrides size, but otherwise, prioritize splitting. Do not create massive blocks.

### CRITICAL CONTENT RULES
$summarizationRule
2. **PRESERVE FORMATTING**: Do NOT remove newlines (`\n`), tabs, or spacing from stat blocks, tables, or item descriptions. The rigid structure must remain intact.
3. **NO METADATA IN TEXT**: Put tags and questions EXCLUSIVELY in their dedicated JSON arrays. Never print them inside the `content` string.
4. **SUBJECT IN METADATA**: The tags array AND every single question MUST explicitly include the main subject/entity name of the chunk. Never use pronouns like "it" or "they" in questions.
5. **MINIMUM METADATA**: You MUST generate at least 5 tags and at least 3 questions per output chunk. Do not be lazy.
$contextRule

### FIGURE RULES
Preserve `figure_path` if present. If merging chunks with different figures, keep the most relevant or split the chunks to preserve both. Return "" if no figure.
$extraInstructionsBlock
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
