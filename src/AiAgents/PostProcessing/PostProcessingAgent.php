<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing;

use Illuminate\Support\Facades\Log;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use RuntimeException;
use SimoneBianco\LaravelRagChunks\AiAgents\RotableAgent;
use TypeError;

class PostProcessingAgent extends RotableAgent
{
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
    protected bool $createIndex = false;
    protected array $currentIndex = [];
    protected ?string $activeContextFromPreviousChunking = null;
    protected ?string $batchContextBefore = null;
    protected ?string $batchContextAfter = null;
    protected ?string $usefulInfoFromPreviousChunking = null;

    public function __construct(
        string $key,
        array $injectConfig = [],
    ) {
        parent::__construct($key);

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

    public function withCreateIndex(bool $createIndex = false): self
    {
        $this->createIndex = $createIndex;
        return $this;
    }

    public function withCurrentIndex(array $currentIndex = []): self
    {
        $this->currentIndex = array_values(array_filter(array_map(
            static fn ($item) => is_string($item) ? trim($item) : null,
            $currentIndex
        )));

        Log::channel('document-queue')->debug('[PostProcessingAgent] withCurrentIndex', [
            'current_index_count' => count($this->currentIndex),
            'current_index_values' => $this->currentIndex,
        ]);

        return $this;
    }

    public function withActiveContextFromPreviousChunking(?string $activeContext): self
    {
        $this->activeContextFromPreviousChunking = !empty($activeContext) ? trim($activeContext) : null;
        return $this;
    }

    public function withBatchBoundaryContext(?string $before, ?string $after): self
    {
        $this->batchContextBefore = $before;
        $this->batchContextAfter = $after;
        return $this;
    }

    public function withUsefulInfoFromPreviousChunking(?string $usefulInfo): self
    {
        $this->usefulInfoFromPreviousChunking = !empty($usefulInfo) ? trim($usefulInfo) : null;
        return $this;
    }

    public function structuredOutput(): array
    {
        return [
            'name' => 'postprocess_chunks_v2',
            'strict' => true,
            'schema' => $this->getResponseSchema(),
        ];
    }

    protected function getResponseSchema(): array
    {
        if ($this->cleanText && $this->summarization) {
            $contentDescription = 'The cleaned, formatted, and summarized text of the chunk. Fix broken characters/encoding, merge arbitrary line breaks, and remove OCR noise, then condense to core meaning using an EXTRACTIVE approach (delete less relevant sentences first, avoid rewriting facts). Stats, numbers, entities, and technical terms MUST be preserved. STRICTLY FORBIDDEN to include words like "Tags:" or "Questions:" inside this field.';
        } elseif ($this->cleanText) {
            $contentDescription = 'The cleaned and perfectly formatted text of the chunk. Fix broken encoding/characters, merge arbitrarily broken lines, and remove OCR noise. NEVER alter the actual meaning, rephrase, condense, or drop information. Preserve all entities 100% faithfully. STRICTLY FORBIDDEN to include words like "Tags:" or "Questions:" inside this field.';
        } else {
            $contentDescription = 'The exact text of the chunk, BUT with corrected formatting. You MUST fix broken encoding (e.g., unicode artifacts), repair garbled characters, and join mid-sentence line breaks. ABSOLUTELY FORBIDDEN to omit, condense, or change the underlying information. STRICTLY FORBIDDEN to include words like "Tags:" or "Questions:" inside this field.';
        }

        $contentDescription .= ' CRITICAL FIDELITY: NEVER invent entities, objects, characters, events, relationships, or mechanics that are not in the source. NEVER swap object/entity type (example: chest -> person). Preserve polarity and intent exactly (example: rivalry must not become camaraderie). Preserve numbers, DCs, dice expressions, units, and constraints exactly as written.';

        if ($this->contextInjection) {
            $contentDescription .= ' May include a brief injected context at the very beginning if the data is highly abstract.';
        } else {
            $contentDescription .= ' STRICTLY FORBIDDEN to include meta-commentary like "These chunks describe...", "Here is...", or "This text covers...". Start IMMEDIATELY with the source text.';
        }

        $deterministicTagProperties = [];
        $deterministicTagRequired = [];
        $allowedFigurePaths = array_values(array_filter(array_unique(array_map(function (array $chunk): string {
            return isset($chunk['figure_path']) && is_string($chunk['figure_path'])
                ? trim($chunk['figure_path'])
                : '';
        }, $this->chunks))));
        $figurePathProperty = [
            'type' => 'string',
            'description' => 'The path to the figure/image. MUST be exactly one of the provided input figure_path values, or empty string "" when there is no relevant figure.',
        ];
        if (!empty($allowedFigurePaths)) {
            $figurePathProperty['enum'] = [...$allowedFigurePaths, ''];
        }

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

        $chapterProperty = [];
        $chapterRequired = [];
        if ($this->createIndex) {
            $chapterProperty = [
                'chapter_title' => [
                    'type' => 'string',
                    'description' => 'Title of the chapter',
                ],
            ];
            $chapterRequired = ['chapter_title'];
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
                'active_context_for_next_chunking' => [
                    'type' => 'string',
                    'description' => 'Short high-level context label to help the next batch keep continuity (for example current section/chapter topic). Keep it concise; return empty string if not useful.',
                ],
                'useful_info_for_next_chunking' => [
                    'type' => 'string',
                    'description' => 'Put there useful information for next chunking, for instance if the last chunk you received is cut and the next part will be handled by the next agent; keep as short as possible'
                ],
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
                                ...$figurePathProperty,
                            ],
                            ...$chapterProperty,
                            ...$deterministicTagProperties,
                        ],
                        'required' => ['content', 'tags', 'questions', 'figure_path', ...$chapterRequired, ...$deterministicTagRequired],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['active_context_for_next_chunking', 'useful_info_for_next_chunking', 'chunks'],
            'additionalProperties' => false,
        ];
    }

    public function instructions(): string
    {
        $contextRule = $this->contextInjection
            ? "7. **CONTEXT INJECTION**: For highly abstract data (e.g., an isolated table), prepend a brief clarifying context to the `content`."
            : "7. **NO META-COMMENTARY (CRITICAL)**: NEVER start the `content` with conversational phrases like \"These chunks describe...\". Start immediately with the raw text.";

        if ($this->cleanText && $this->summarization) {
            $summarizationRule = "1. **CLEAN, FORMAT & SUMMARIZE**: Fix broken text, merge split lines, remove OCR garbage AND condense to core meaning. Use extractive compression by removing less relevant parts first; avoid rewriting facts unless needed for OCR repair. STRICTLY FORBIDDEN to remove entities, proper nouns, technical terms, numerical data, or code.";
            $chunkingModeRules = "- You MUST NOT output a 1:1 mapping of input to output.\n- Separate distinct topics (e.g., isolate a conceptual explanation from a technical table).\n- AGGRESSIVE SPLITTING: If a combined topic exceeds this limit, you MUST split it into smaller, logical sub-chunks. Semantic integrity (keeping a structured block together) overrides size, but otherwise, prioritize splitting. Do not create massive blocks. Do not stop cut sentences.";
        } elseif ($this->cleanText) {
            $summarizationRule = "1. **CLEAN & FORMAT ONLY**: Fix text encoding, repair garbled characters, merge mid-sentence line breaks, and remove OCR noise. NEVER condense, rephrase, or omit content. Preserve meaning 100% faithfully.";
            $chunkingModeRules = "- CONSERVATIVE MODE (summarization=false): 1:1 mapping is allowed and preferred when chunks are already coherent.\n- Merge or split ONLY when strictly necessary for readability, sentence integrity, or hard size limits.\n- Do not restructure for style.";
        } else {
            $summarizationRule = "1. **FORMAT REPAIR ONLY**: Do NOT alter the meaning or condense the text. However, you MUST fix bad formatting: join artificially broken lines, repair broken unicode/encoding, and correct clear OCR typos. All original information MUST survive intact.";
            $chunkingModeRules = "- CONSERVATIVE MODE (summarization=false): 1:1 mapping is allowed and preferred when chunks are already coherent.\n- Merge or split ONLY when strictly necessary for readability, sentence integrity, or hard size limits.\n- Do not restructure for style.";
        }

        $extraInstructionsBlock = !empty($this->extraInstruction)
            ? "\n### EXTRA INSTRUCTIONS\n{$this->extraInstruction}\n"
            : "";

        $boundaryBlock = '';
        if ($this->batchContextBefore !== null || $this->batchContextAfter !== null) {
            $boundaryBlock = "\n### BATCH BOUNDARY CONTEXT (FOR AWARENESS ONLY — DO NOT REPRODUCE IN OUTPUT)\n";
            $boundaryBlock .= "This batch is part of a larger sequential document. The snippets below are provided EXCLUSIVELY to help you understand continuity at the edges. They are NOT part of the input to process and MUST NOT appear in any `content` field.\n";
            if ($this->batchContextBefore !== null) {
                $boundaryBlock .= "**Text immediately preceding this batch:** `{$this->batchContextBefore}`\n";
            }
            if ($this->batchContextAfter !== null) {
                $boundaryBlock .= "**Text immediately following this batch:** `{$this->batchContextAfter}`\n";
            }
        }

        $iterativeUsefulInfoBlock = '';
        if ($this->usefulInfoFromPreviousChunking !== null) {
            $iterativeUsefulInfoBlock = "\n### ITERATIVE HANDOFF FROM PREVIOUS BATCH\n{$this->usefulInfoFromPreviousChunking}\n";
        }

        $indexingBlock = '';
        if ($this->createIndex) {
            $existingIndex = !empty($this->currentIndex)
                ? implode(', ', $this->currentIndex)
                : 'none';
            $indexingBlock = "\n### DYNAMIC INDEXING (ENABLED)\nAssign one `chapter_title` to every output chunk.\n\nYou have the current index titles from previous batches: {$existingIndex}\n\nINDEX RULES\n- The goal is clustering, not summarizing each chunk.\n- Multiple chunks should share the exact same chapter title when they belong to the same broader section.\n- Prefer reusing the same chapter title whenever the fit is reasonable.\n- Minimize the number of distinct chapter titles.\n- Do not create a new title just because a chunk is more specific, uses different wording, or covers a sub-point of the same topic.\n- Create a different title only when using the same one would be clearly wrong.\n- Prefer broader but still accurate chapter titles over narrow per-chunk titles.\n- Use the existing index for naming consistency when helpful.\n- Keep titles short, clear, specific, and section-level.\n- Do not use vague titles like \"Miscellaneous\", \"Other\", \"Notes\", or \"General\".\n- Do not include the document name in the title.\n- Use natural words separated by spaces for `chapter_title`. Do not use snake_case or kebab-case.\n\nINDEX OUTPUT RULES\n- Assign exactly one `chapter_title` to every output chunk.\n- Do not omit any output chunk.\n- Do not add extra properties.\n";
        }

        $estimatedWords = (int)($this->preferredChunkLength / 6);

        $currentActiveContext = $this->activeContextFromPreviousChunking ?? 'none';
        $contextBlock = "### CURRENT ACTIVE CONTEXT (From previous iterations)\n{$currentActiveContext}\n";

        return <<<INSTRUCTIONS
### PERSONA
You are an expert data processor and dynamic chunker for RAG systems. You handle agnostic text sources (documentation, manuals, code, books).

$this->documentContext

$contextBlock

### GOAL & DYNAMIC CHUNKING
Analyze the provided raw chunks. Clean, merge, or split them to form highly cohesive, atomic semantic units.
- $chunkingModeRules
- TARGET SIZE: Maximum $this->preferredChunkLength characters (approximately $estimatedWords words) per chunk. Do not cut in the middle of sentences.

### CRITICAL CONTENT RULES
0. **RULE PRIORITY (CRITICAL)**: Resolve conflicts in this exact order: (1) Content fidelity, (2) preserve entities/literals/relationships, (3) formatting/OCR repair, (4) chunk optimization, (5) tags/questions metadata.
$summarizationRule
2. **NO DATA LOSS (CRITICAL)**: You MUST process ALL provided input. Do not stop halfway. Do not omit paragraphs or sections. Every piece of information from the input must survive in the output, even if transformed/formatted.
3. **PRESERVE ALL ENTITIES**: Treat the data as sacred. Do NOT remove specific items, variables, names, or domain-specific terminology.
4. **PRESERVE INTENTIONAL FORMATTING**: While fixing bad OCR line-breaks, do NOT remove intentional formatting like newlines (`\n`), tabs, or spacing from lists, tables, or code blocks.
4.1 **AMBIGUITY RULE**: If a token span is ambiguous, preserve original wording instead of normalizing or inferring.
4.2 **OCR REPAIR SCOPE**: Only fix obvious mechanical OCR/encoding defects. Never reinterpret semantics while repairing text.
5. **NO METADATA IN TEXT**: Put tags and questions EXCLUSIVELY in their dedicated JSON arrays. Never print them inside the `content` string.
5.1 **CONTENT-FIRST**: `content` is strictly more important than tags/questions. Metadata MUST be derived from `content` and MUST NEVER influence or distort `content`.
6. **SUBJECT IN METADATA**: The tags array AND every single question MUST explicitly include the main subject/entity name of the chunk. Never use pronouns like "it" or "they" in questions.
$contextRule
8. **ANTI-INVENTION (CRITICAL)**: Never add content that is not present in source text. If uncertain, keep source wording; do not guess.
9. **NO SEMANTIC FLIPS (CRITICAL)**: Never invert roles/relationships/sentiment (ally vs rival, support vs threat, optional vs mandatory, invitation vs summons).
10. **PRESERVE TECHNICAL LITERALS**: Keep all numerical values and mechanical literals unchanged (DC values, dice notation like `3d10`, distances, durations, requirements, constraints).
11. **PRESERVE VOICE WHEN POSSIBLE**: Keep stylistic tone (ironic, theatrical, dark humor) if present in source; do not neutralize tone into generic exposition.
12. **SELF-INTEGRITY CHECK (CRITICAL)**: Before returning JSON, ensure there are no broken/fused token artifacts (e.g., `word...word`, orphan fragments, accidental token collisions). If uncertain, keep the original local wording.

### FIGURE RULES
Preserve `figure_path` if present. If merging chunks with different figures, keep the most relevant or split the chunks to preserve both. Return "" if no figure.

### ITERATIVE HANDOFF FIELD (CRITICAL)
- You MUST always return `active_context_for_next_chunking`.
- Use it as a short section/chapter continuity label for the next batch.
- Reuse prior active context when still valid; change only when context really shifts.
- If there is no useful active context, return an empty string "".
- You MUST always return `useful_info_for_next_chunking`.
- Use it to pass short, practical continuity hints for the next batch (e.g., unfinished sentence, truncated list or tables, expected continuation, unresolved reference).
- You MUST include only directly observed boundary facts from the current input; never infer or invent future content.
- If there is no following text context (`Text immediately following this batch` is missing), do not speculate about future content. In that case return `""` unless the current batch clearly ends with an actual truncated fragment.
- Keep it concise and actionable.
- If there is nothing useful to hand off, return an empty string "".

### CHUNKS OUTPUT (CRITICAL)
- For dirty chunks are dirty (non-UTF-8 characters that don't have meaning or new line in the middle of a sentence), you MUST produce a clean output instead with a well formatted text; don't put unnecessary dirty characters and unnecessary new lines

$boundaryBlock
$iterativeUsefulInfoBlock
$indexingBlock
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

        $chunks = is_array($response['chunks'] ?? null) ? $response['chunks'] : [];
        $chapterTitles = [];
        foreach ($chunks as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $chapterTitle = trim((string)($chunk['chapter_title'] ?? ''));
            if ($chapterTitle !== '') {
                $chapterTitles[] = $chapterTitle;
            }
        }

        Log::channel('document-queue')->debug('[PostProcessingAgent] response summary', [
            'response_chunks_count' => count($chunks),
            'response_active_context' => is_string($response['active_context_for_next_chunking'] ?? null)
                ? trim($response['active_context_for_next_chunking'])
                : '',
            'response_useful_info_len' => is_string($response['useful_info_for_next_chunking'] ?? null)
                ? mb_strlen(trim($response['useful_info_for_next_chunking']))
                : 0,
            'response_chapter_titles_count' => count($chapterTitles),
            'response_chapter_titles_unique' => array_values(array_unique($chapterTitles)),
        ]);

        return [
            'active_context_for_next_chunking' => is_string($response['active_context_for_next_chunking'] ?? null)
                ? trim($response['active_context_for_next_chunking'])
                : '',
            'useful_info_for_next_chunking' => is_string($response['useful_info_for_next_chunking'] ?? null)
                ? trim($response['useful_info_for_next_chunking'])
                : '',
            'chunks' => $response['chunks'] ?? [],
        ];
    }

    public function prompt($message)
    {
        return $message;
    }
}
