<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use RuntimeException;
use SimoneBianco\LaravelRagChunks\AiAgents\RotableAgent;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SetContext;
use TypeError;

class PostProcessingAgent extends RotableAgent
{
    protected const string CONTEXT_KEY = 'postprocessing_context';

    protected $history = InMemoryStorage::class;
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

    public function __construct(
        string $key,
        array $injectConfig = [],
        protected bool $trackContext = true,
    ) {
        parent::__construct($key);

        if ($this->trackContext) {
            $this->withTool(new SetContext(self::CONTEXT_KEY));
        }

        $config = config('rag_chunks.agents.postprocessor', []);
        $this->config['provider'] = $injectConfig['provider'] ?? $config['provider'] ?? 'openai';
        $this->config['model'] = $injectConfig['model'] ?? $config['model'] ?? 'gpt-5-mini';
        $this->preferredChunkLength = $injectConfig['preferred_chunk_length'] ?? $config['preferred_chunk_length'] ?? 600;

        Log::channel('document-queue')->debug('Postprocessor config', $this->config);
    }

    public function withChunks(array $chunks): self
    {
        $this->chunks = $chunks;

        Log::channel('document-queue')->debug('CHUNKS', [
            'count' => count($this->chunks),
            'total_characters' => strlen(json_encode($this->chunks))
        ]);

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
            $contentDescription = 'The cleaned, formatted, and summarized text of the chunk. Fix broken characters/encoding, merge arbitrary line breaks, and remove OCR noise, then condense to core meaning. Stats, numbers, entities, and technical terms MUST be preserved. STRICTLY FORBIDDEN to include words like "Tags:" or "Questions:" inside this field.';
        } elseif ($this->cleanText) {
            $contentDescription = 'The cleaned and perfectly formatted text of the chunk. Fix broken encoding/characters, merge arbitrarily broken lines, and remove OCR noise. NEVER alter the actual meaning, rephrase, condense, or drop information. Preserve all entities 100% faithfully. STRICTLY FORBIDDEN to include words like "Tags:" or "Questions:" inside this field.';
        } else {
            $contentDescription = 'The exact text of the chunk, BUT with corrected formatting. You MUST fix broken encoding (e.g., unicode artifacts), repair garbled characters, and join mid-sentence line breaks. ABSOLUTELY FORBIDDEN to omit, condense, or change the underlying information. STRICTLY FORBIDDEN to include words like "Tags:" or "Questions:" inside this field.';
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

        Log::channel('document-queue')->debug('POSTPROCESSING SETTINGS', [
            'summarization' => $this->summarization,
            'cleanText' => $this->cleanText,
            'contextInjection' => $this->contextInjection,
        ]);

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
            ? "7. **CONTEXT INJECTION**: For highly abstract data (e.g., an isolated table), prepend a brief clarifying context to the `content`."
            : "7. **NO META-COMMENTARY (CRITICAL)**: NEVER start the `content` with conversational phrases like \"These chunks describe...\". Start immediately with the raw text.";

        if ($this->cleanText && $this->summarization) {
            $summarizationRule = "1. **CLEAN, FORMAT & SUMMARIZE**: Fix broken text, merge split lines, remove OCR garbage AND condense to core meaning. STRICTLY FORBIDDEN to remove entities, proper nouns, technical terms, numerical data, or code.";
        } elseif ($this->cleanText) {
            $summarizationRule = "1. **CLEAN & FORMAT ONLY**: Fix text encoding, repair garbled characters, merge mid-sentence line breaks, and remove OCR noise. NEVER condense, rephrase, or omit content. Preserve meaning 100% faithfully.";
        } else {
            $summarizationRule = "1. **FORMAT REPAIR ONLY**: Do NOT alter the meaning or condense the text. However, you MUST fix bad formatting: join artificially broken lines, repair broken unicode/encoding, and correct clear OCR typos. All original information MUST survive intact.";
        }

        $extraInstructionsBlock = !empty($this->extraInstruction)
            ? "\n### EXTRA INSTRUCTIONS\n{$this->extraInstruction}\n"
            : "";

        $estimatedWords = (int)($this->preferredChunkLength / 6);

        $contextBlock = '';
        if ($this->trackContext) {
            $savedContext = Context::getHidden(self::CONTEXT_KEY, '');
            $activeContextBlock = !empty($savedContext)
                ? "### CURRENT ACTIVE CONTEXT (From previous iterations)\n{$savedContext}\n"
                : "### CURRENT ACTIVE CONTEXT\nNone yet.\n";
            $contextBlock = <<<CONTEXT
$activeContextBlock
### STATE & CONTEXT MANAGEMENT (CRITICAL)
You have access to a tool to set the context for the NEXT processing iteration.
- **Purpose**: To inform the next batch where we are in the document (e.g., "Chapter 2 - Architecture - Introduction").
- **EXECUTION RULE (CRITICAL)**: You MUST call this tool AT MOST ONCE per execution. DO NOT call it in a loop. Determine the overarching context of these chunks, call the tool ONCE if it has changed from the CURRENT ACTIVE CONTEXT, and then IMMEDIATELY proceed to generate the final JSON output.
- **Updating**: If the context hasn't changed, you do not need to call the tool at all.
CONTEXT;

            Context::forgetHidden(self::CONTEXT_KEY);
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
- AGGRESSIVE SPLITTING: If a combined topic exceeds this limit, you MUST split it into smaller, logical sub-chunks. Semantic integrity (keeping a structured block together) overrides size, but otherwise, prioritize splitting. Do not create massive blocks. Do not stop cut sentences.

### CRITICAL CONTENT RULES
$summarizationRule
2. **NO DATA LOSS (CRITICAL)**: You MUST process ALL provided input. Do not stop halfway. Do not omit paragraphs or sections. Every piece of information from the input must survive in the output, even if transformed/formatted.
3. **PRESERVE ALL ENTITIES**: Treat the data as sacred. Do NOT remove specific items, variables, names, or domain-specific terminology.
4. **PRESERVE INTENTIONAL FORMATTING**: While fixing bad OCR line-breaks, do NOT remove intentional formatting like newlines (`\n`), tabs, or spacing from lists, tables, or code blocks.
5. **NO METADATA IN TEXT**: Put tags and questions EXCLUSIVELY in their dedicated JSON arrays. Never print them inside the `content` string.
6. **SUBJECT IN METADATA**: The tags array AND every single question MUST explicitly include the main subject/entity name of the chunk. Never use pronouns like "it" or "they" in questions.
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
