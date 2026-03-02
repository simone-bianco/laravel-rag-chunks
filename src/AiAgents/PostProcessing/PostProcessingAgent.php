<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing;

use Illuminate\Support\Facades\Context;
use LarAgent\Agent;
use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use RuntimeException;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SetContext;
use TypeError;

class PostProcessingAgent extends Agent
{
    protected const string CONTEXT_KEY = 'postprocessing_context';

    protected $history = CacheStorage::class;
    protected array $chunks = [];
    protected $mcpServers = [];
    protected string $documentContext = '';
    protected array $config = [];
    protected int $preferredChunkLength = 600;
    protected bool $contextInjection = false;
    protected bool $cleanText = false;
    protected bool $summarization = false;
    protected string $extraInstruction = '';
    protected array $tagsByType = [];
    protected string $agentKey = '';

    public function __construct(
        string $key,
        array $injectConfig = [],
        protected bool $trackContext = true,
    ) {
        parent::__construct($key);

        $this->agentKey = $key;

        if ($this->trackContext) {
            $this->withTool(new SetContext(self::CONTEXT_KEY));
        }

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

    public function withCleanText(bool $cleanText = false): self
    {
        $this->cleanText = $cleanText;
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

    public function withTagsByType(array $tagsByType = []): self
    {
        $this->tagsByType = $tagsByType;
        return $this;
    }

    protected function getResponseSchema(): array
    {
        if ($this->cleanText && $this->summarization) {
            $contentDescription = 'The cleaned and summarized text of the chunk. Remove OCR garbage, stray page numbers, and artifacts, then condense to core meaning. Stats and numbers are always preserved. NEVER remove entities, proper nouns, technical terms, code snippets, or numerical data. STRICTLY FORBIDDEN to include words like "Tags:" or "Questions:" inside this field.';
        } elseif ($this->cleanText) {
            $contentDescription = 'The cleaned text of the chunk. Remove OCR noise (broken page breaks, stray page numbers, garbled characters) but NEVER alter content, rephrase, condense, or summarize. Preserve all terminology, entities, and meaning 100% faithfully. STRICTLY FORBIDDEN to include words like "Tags:" or "Questions:" inside this field.';
        } else {
            $contentDescription = 'The exact, unmodified text of the chunk. ABSOLUTELY FORBIDDEN to alter, clean, remove, or rephrase ANY part of the text. Copy it verbatim. STRICTLY FORBIDDEN to include words like "Tags:" or "Questions:" inside this field.';
        }

        if ($this->contextInjection) {
            $contentDescription .= ' May include a brief injected context at the very beginning if the data is highly abstract.';
        } else {
            $contentDescription .= ' STRICTLY FORBIDDEN to include meta-commentary like "These chunks describe...", "Here is...", or "This text covers...". Start IMMEDIATELY with the source text.';
        }

        $deterministicTagProperties = [];
        $deterministicTagRequired = [];
        if (!empty($this->tagsByType)) {
            foreach ($this->tagsByType as $type => $tags) {
                $deterministicTagProperties["tags_$type"] = [
                    'type' => 'array',
                    'description' => "Deterministic tags of type '$type'. MUST pick ONLY from the provided slug enum values (kebab-case identifiers). Leave empty if none apply.",
                    'items' => [
                        'type' => 'string',
                        'enum' => $tags,
                    ],
                ];
                $deterministicTagRequired[] = "tags_$type";
            }
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
                                'description' => $contentDescription,
                            ],
                            'tags' => [
                                'type' => 'array',
                                'description' => 'List of 5 to 10 semantic tags for RAG retrieval. CRITICAL: The main subject/entity of the chunk MUST be included as a tag. Must be strictly LOWERCASE and SLUG_CASE. Tag both the subject and the action/event. Do not generate fewer than 5 tags.',
                                'items' => ['type' => 'string'],
                            ],
                            'questions' => [
                                'type' => 'array',
                                'description' => 'List of 3 to 5 reverse-engineered questions that this specific chunk answers perfectly. CRITICAL: Every single question MUST explicitly include the subject or entity name of the chunk. Do not generate fewer than 3 questions.',
                                'items' => ['type' => 'string'],
                            ],
                            'figure_path' => [
                                'type' => 'string',
                                'description' => 'The path to the figure/image. Return an empty string "" if there is no relevant figure.',
                            ],
                            ...$deterministicTagProperties,
                        ],
                        'required' => ['content', 'tags', 'questions', 'figure_path', ...$deterministicTagRequired],
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
        $contextRule = $this->contextInjection
            ? "6. **CONTEXT INJECTION**: For highly abstract data (e.g., an isolated table), prepend a brief clarifying context to the `content`."
            : "6. **NO META-COMMENTARY (CRITICAL)**: NEVER start the `content` with conversational phrases like \"These chunks describe...\". Start immediately with the raw text.";

        if ($this->cleanText && $this->summarization) {
            $summarizationRule = "1. **CLEAN AND SUMMARIZE**: Remove OCR garbage (broken characters, stray page numbers, garbled text) AND condense to core meaning. STRICTLY FORBIDDEN to remove entities, proper nouns, technical terms, numerical data, or code. Stats and numbers are always preserved.";
        } elseif ($this->cleanText) {
            $summarizationRule = "1. **CLEAN ONLY — NO SUMMARIZATION**: Remove OCR noise (broken page breaks, stray page numbers, garbled characters) but NEVER condense, rephrase, or omit any content. Preserve all terminology, entities, and meaning 100% faithfully.";
        } else {
            $summarizationRule = "1. **ZERO TEXT MODIFICATION**: Do NOT alter the text in any way. Preserve ALL content EXACTLY as written. Never clean, rephrase, or condense. Only merge/split chunks for cohesion.";
        }

        $extraInstructionsBlock = !empty($this->extraInstruction)
            ? "\n### EXTRA INSTRUCTIONS\n{$this->extraInstruction}\n"
            : "";

        $estimatedWords = (int)($this->preferredChunkLength / 6);

        $contextKey = "{$this->agentKey}_" . self::CONTEXT_KEY;
        $savedContext = Context::getHidden($contextKey);

        $contextBlock = '';
        if ($this->trackContext) {
            $activeContextBlock = !empty($savedContext)
                ? "### CURRENT ACTIVE CONTEXT (From previous iterations)\n{$savedContext}\n"
                : "### CURRENT ACTIVE CONTEXT\nNone yet.\n";
            $contextBlock = <<<CONTEXT
$activeContextBlock
### STATE & CONTEXT MANAGEMENT (CRITICAL)
You have access to a tool to set the context for the NEXT processing iteration. You MUST use this tool frequently whenever you detect structural metadata in the current text (e.g., a new Chapter heading, Section title, or major entity shift).
- **Purpose**: To inform the next agent iteration where we are in the document, ensuring consistent tagging and context across chunk boundaries.
- **What to Save**: Store brief, hierarchical markers (e.g., "Chapter 2 - Architecture", "Subject: Routing"). You may add minor extra info, but DO NOT overdo it. Keep it concise to avoid confusing the next iteration.
- **Updating & Flushing**: The current context is passed to you above in `CURRENT ACTIVE CONTEXT`. If the chunks you are processing move into a new chapter/section, the old context is no longer valid. You MUST call the tool to overwrite and replace the old context with the new structural location. If the context needs to be completely cleared, overwrite it using the tool. You are entirely responsible for keeping this cross-iteration state accurate and up-to-date.
CONTEXT;
        }

        return <<<INSTRUCTIONS
### PERSONA
You are an expert data processor and dynamic chunker for RAG systems. You handle agnostic text sources (documentation, manuals, code, books).

$this->documentContext

$contextBlock

### GOAL & DYNAMIC CHUNKING
Analyze the provided raw chunks. Clean, merge, or split them to form highly cohesive, atomic semantic units.
- You MUST NOT output a 1:1 mapping of input to output.
- Separate distinct topics (e.g., isolate a conceptual explanation from a technical table).
- TARGET SIZE: Maximum $this->preferredChunkLength characters (approximately $estimatedWords words) per chunk. Do not cut in the middle of sentences.
- AGGRESSIVE SPLITTING: If a combined topic exceeds this limit, you MUST split it into smaller, logical sub-chunks. Semantic integrity (keeping a structured block together) overrides size, but otherwise, prioritize splitting. Do not create massive blocks.

### CRITICAL CONTENT RULES
$summarizationRule
2. **PRESERVE ALL ENTITIES**: Treat the raw text as sacred. Do NOT remove specific items, variables, names, or domain-specific terminology.
3. **PRESERVE FORMATTING**: Do NOT remove newlines (`\n`), tabs, or spacing from structured data, tables, or code blocks. The rigid structure must remain intact.
4. **NO METADATA IN TEXT**: Put tags and questions EXCLUSIVELY in their dedicated JSON arrays. Never print them inside the `content` string.
5. **SUBJECT IN METADATA**: The tags array AND every single question MUST explicitly include the main subject/entity name of the chunk. Never use pronouns like "it" or "they" in questions.
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
