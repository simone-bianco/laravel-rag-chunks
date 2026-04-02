<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use RuntimeException;
use SimoneBianco\LaravelRagChunks\AiAgents\RotableAgent;
use TypeError;

class ImagePostProcessingAgent extends RotableAgent
{
    protected $history = InMemoryStorage::class;
    protected array $config = [];
    protected string $documentContext = '';
    protected string $pageImageDataUrl = '';
    protected int $pageNumber = 1;
    protected int $preferredChunkLength = 600;
    protected string $extraInstruction = '';
    protected array $tagsByType = [];
    protected bool $createIndex = false;
    protected array $currentIndex = [];
    protected ?string $activeContextFromPreviousChunking = null;
    protected ?string $usefulInfoFromPreviousChunking = null;
    protected int $processedChunksCount = 0;
    protected int $totalDocumentChunks = 0;

    public function __construct(
        string $key,
        array $injectConfig = [],
    ) {
        parent::__construct($key);

        $config = config('rag_chunks.agents.image_postprocessor', []);
        $this->config['provider'] = $injectConfig['provider'] ?? $config['provider'] ?? 'openai';
        $this->config['model'] = $injectConfig['model'] ?? $config['model'] ?? 'gpt-5-mini';
        $this->preferredChunkLength = $injectConfig['preferred_chunk_length'] ?? $config['preferred_chunk_length'] ?? 600;
    }

    public function withDocumentContext(?string $context): self
    {
        if (!empty($context)) {
            $this->documentContext = "\n### DOCUMENT CONTEXT\n$context";
        }

        return $this;
    }

    public function withPageImageDataUrl(string $dataUrl): self
    {
        $this->pageImageDataUrl = $dataUrl;
        return $this;
    }

    public function withPageNumber(int $pageNumber): self
    {
        $this->pageNumber = max(1, $pageNumber);
        return $this;
    }

    public function withPreferredChunkLength(int $length): self
    {
        $this->preferredChunkLength = max(100, $length);
        return $this;
    }

    public function withExtraInstructions(?string $extraInstructions = ''): self
    {
        $this->extraInstruction = $extraInstructions ?: '';
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

        return $this;
    }

    public function withActiveContextFromPreviousChunking(?string $activeContext): self
    {
        $this->activeContextFromPreviousChunking = !empty($activeContext) ? trim($activeContext) : null;
        return $this;
    }

    public function withUsefulInfoFromPreviousChunking(?string $usefulInfo): self
    {
        $this->usefulInfoFromPreviousChunking = !empty($usefulInfo) ? trim($usefulInfo) : null;
        return $this;
    }

    public function withProcessedChunksCount(int $count): self
    {
        $this->processedChunksCount = max(0, $count);
        return $this;
    }

    public function withTotalDocumentChunks(int $count): self
    {
        $this->totalDocumentChunks = max(0, $count);
        return $this;
    }

    public function structuredOutput(): array
    {
        return [
            'name' => 'postprocess_pages_by_image_v1',
            'strict' => true,
            'schema' => $this->getResponseSchema(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getResponseSchema(): array
    {
        $contentDescription = 'NEAR-VERBATIM transcription of readable text visible on the current page image only. TRANSCRIPTION-ONLY MODE: do not summarize, do not paraphrase, do not compress, do not expand, do not infer missing glue text, do not restate page meaning. You may perform only tiny OCR corruption repair when unambiguous. Preserve visible reading order, headings, bullets, numbered items, quotes, notes, boxed text, letters, tables, and stat/rule values with maximal fidelity. For structured content, do NOT normalize into a canonical template and do NOT use domain knowledge to reconstruct missing parts. Keep separate visual/textual artifacts as separate chunks when appropriate. NEVER end a chunk mid-sentence. Target about 600 chars per chunk as a soft limit; slight overrun is allowed. If a block is too large, split it into multiple faithful chunks rather than compressing or omitting content. STRICTLY FORBIDDEN to include words like "Tags:" or "Questions:" inside this field.';

        return PostProcessingResponseSchemaFactory::build(
            contentDescription: $contentDescription,
            tagsByType: $this->tagsByType,
            createIndex: $this->createIndex,
            allowedFigurePaths: [],
        );
    }

    public function instructions(): string
    {
        $currentActiveContext = $this->activeContextFromPreviousChunking ?? 'none';
        $usefulInfo = $this->usefulInfoFromPreviousChunking ?? '';
        $extraInstructionsBlock = !empty($this->extraInstruction)
            ? "\n### EXTRA INSTRUCTIONS\n{$this->extraInstruction}\n"
            : '';

        $indexingBlock = '';
        if ($this->createIndex) {
            $existingIndex = !empty($this->currentIndex)
                ? implode(', ', $this->currentIndex)
                : 'none';

            $indexingBlock = "\n### DYNAMIC INDEXING (ENABLED)\n"
                . "Assign one `chapter_title` to each output chunk.\n"
                . "Current index titles: {$existingIndex}\n"
                . "Reuse existing titles whenever semantically correct.\n"
                . "Create a new title only when reuse would be clearly wrong.\n";
        }

        return <<<INSTRUCTIONS
### ROLE
You are a STRICT TEXT TRANSCRIBER with chunking for PDF page images.

### MODE
- Active mode: `TRANSCRIPTION_ONLY_MODE`.
- Mission: extract only readable textual content from the current page image and split it into faithful chunks.
- You are NOT a summarizer.
- You are NOT a paraphraser.
- You are NOT a visual describer unless explicitly instructed otherwise.
{$this->documentContext}

### PAGE CONTEXT
- Current page number: {$this->pageNumber}
- Already processed document chunks: {$this->processedChunksCount}
- Expected total document chunks: {$this->totalDocumentChunks}
- Active continuity context from previous chunking: {$currentActiveContext}
- Useful handoff notes from previous chunking: {$usefulInfo}

### IMPORTANT CONTEXT USAGE RULE
- Document context, previous continuity context, useful handoff notes, tags/questions requirements, and indexing instructions may be used ONLY to improve metadata decisions such as tags, questions, chapter titles, and boundary continuity.
- They MUST NOT be used to add, restore, guess, or complete chunk content that is not clearly readable on the current page image.
- If a word, line, heading, or block is not clearly readable on this page, do not reconstruct it from context.

### CORE RULES
1. Transcribe ONLY readable textual content visible on this page. Keep source wording as-is whenever readable.
2. Before chunking, determine the reading order from the page image itself.
3. Default reading order:
   - top-to-bottom within each text block or column
   - left column before right column unless the page clearly indicates another order
   - boxed inserts, side notes, invitations, letters, notes, stat blocks, tables, and similar artifacts must be treated as separate blocks
   - do NOT interleave separate blocks into one invented narrative
4. Allowed transformations are limited to:
   - safe OCR noise cleanup
   - chunk splitting
   - tiny boundary handoff notes
5. NEVER summarize.
6. NEVER paraphrase.
7. NEVER compress content to fit the chunk limit.
8. NEVER invent entities, facts, relationships, mechanics, connector prose, or missing glue text.
9. NEVER use domain knowledge or expected templates to normalize or reconstruct structured content.
10. If text is unclear, keep the conservative visible fragment or omit it.
11. Split into coherent chunks but do NOT merge separate artifacts such as letters, flyers, side notes, boxed text, tables, or stat blocks into one invented narrative.
12. Preserve headings, section labels, bullets, numbered items, quotes, notes, table rows, names, numbers, rule text, and stat values with maximal fidelity.
13. For tables, numbered lists, and structured entries:
   - preserve visible numbering exactly
   - preserve one item or row per line whenever possible
   - do not infer missing rows or numbers
   - do not reorder fields into a canonical schema
14. NEVER end chunk content mid-sentence.
15. Target about {$this->preferredChunkLength} characters per chunk as a SOFT limit.
16. Slight overrun is allowed when needed to preserve fidelity and avoid bad cuts.
17. If a text block is too large, you MUST split it into multiple faithful chunks rather than compressing, omitting, or fusing content.
18. Keep tags/questions OUT of chunk content.
19. CRITICAL ABSOLUTE PROHIBITION: no visual/page-description language. Forbidden examples:
   - "this page contains"
   - "the image shows"
   - "the map depicts"
   - "this likely represents"
20. Ignore non-text image content completely: illustrations, map geometry, icons, scene composition, colors, and layout narration.
21. Do not duplicate information across chunks.
22. Do not copy previous continuity context into current chunk content.
23. `active_context_for_next_chunking` and `useful_info_for_next_chunking` must be minimal boundary metadata only, never summaries of the page.
24. If page has no readable text, return empty chunks and empty continuity fields.
25. When a new major heading or a clearly separate text artifact begins, prefer starting a new chunk there.
$indexingBlock
$extraInstructionsBlock
INSTRUCTIONS;
    }

    public function respond(?string $message = null): string|array|DataModel|MessageInterface
    {
        $this->changeProvider($this->config['provider']);
        $this->model = $this->config['model'];

        if ($this->pageImageDataUrl !== '') {
            $this->withImages([$this->pageImageDataUrl]);
        }

        try {
            $response = parent::respond("Transcribe and chunk this page image (page {$this->pageNumber}) in strict transcription-only mode.");
        } catch (TypeError $e) {
            throw new RuntimeException('AI provider returned null content (transient error or refusal): ' . $e->getMessage(), 0, $e);
        }

        $chunks = is_array($response['chunks'] ?? null) ? $response['chunks'] : [];
        $chunkPreviews = array_values(array_filter(array_map(static function (mixed $chunk): string {
            if (!is_array($chunk)) {
                return '';
            }

            $content = is_string($chunk['content'] ?? null) ? $chunk['content'] : '';
            $normalized = preg_replace('/\s+/', ' ', trim($content)) ?? '';

            return Str::limit($normalized, 50);
        }, $chunks)));
        $activeContext = is_string($response['active_context_for_next_chunking'] ?? null)
            ? trim($response['active_context_for_next_chunking'])
            : '';
        $usefulInfo = is_string($response['useful_info_for_next_chunking'] ?? null)
            ? trim($response['useful_info_for_next_chunking'])
            : '';

        Log::channel('document-queue')->debug('[ImagePostProcessingAgent] response summary', [
            'page_number' => $this->pageNumber,
            'response_chunks_count' => count($chunks),
            'response_chunks_preview_50' => $chunkPreviews,
            'response_active_context' => $activeContext,
            'response_useful_info_len' => mb_strlen($usefulInfo),
        ]);

        return [
            'active_context_for_next_chunking' => $activeContext,
            'useful_info_for_next_chunking' => $usefulInfo,
            'chunks' => $chunks,
        ];
    }

    public function prompt($message)
    {
        return $message;
    }
}
