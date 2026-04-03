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
    protected const ESTIMATED_CHARS_PER_WORD = 6;

    protected $history = InMemoryStorage::class;
    protected array $config = [];
    protected string $documentContext = '';
    protected string $pageImageDataUrl = '';
    protected int $pageNumber = 1;

    /**
     * Backward compatibility:
     * this legacy value is still accepted through config/injection/public API,
     * but every limit sent to the model is normalized and expressed in words.
     */
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
        $this->preferredChunkLength = $injectConfig['preferred_chunk_length']
            ?? $config['preferred_chunk_length']
            ?? 600;
    }

    public function withDocumentContext(?string $context): self
    {
        if (!empty($context)) {
            $this->documentContext = "\n### DOCUMENT CONTEXT\n{$context}";
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
        $targetChunkWords = $this->getTargetChunkWordCount();
        $softMinChunkWords = $this->getSoftChunkWordMinimum();
        $singleChunkThresholdWords = $this->getSingleChunkThresholdWords();
        $hardChunkWordLimit = $this->getHardChunkWordLimit();

        $contentDescription = "NEAR-VERBATIM transcription of readable text visible on the current page image only. "
            . "TRANSCRIPTION-ONLY MODE: do not summarize, do not paraphrase, do not compress, do not expand, do not infer missing glue text, do not restate page meaning. "
            . "You may perform only tiny OCR corruption repair when unambiguous. "
            . "Preserve visible reading order, headings, bullets, numbered items, quotes, notes, boxed text, letters, tables, and stat/rule values with maximal fidelity. "
            . "For structured content, do NOT normalize into a canonical template and do NOT use domain knowledge to reconstruct missing parts. "
            . "Keep separate visual/textual artifacts as separate chunks when appropriate. "
            . "Chunk sizing is WORD-BASED ONLY. "
            . "Target about {$targetChunkWords} words per chunk. "
            . "SOFT MINIMUM: keep chunks at or above {$softMinChunkWords} words whenever possible; you may go shorter only when the visible text block is naturally shorter or a clean artifact boundary requires it. "
            . "If page text clearly exceeds {$singleChunkThresholdWords} words, you MUST output multiple chunks and MUST NOT return a single chunk. "
            . "ABSOLUTE UPPER BOUND: each chunk must stay at or below {$hardChunkWordLimit} words. "
            . "NEVER end a chunk mid-sentence unless no earlier safe boundary exists before {$hardChunkWordLimit} words, in which case split at the nearest whitespace before exceeding the limit. "
            . "If a block is too large, split it into multiple faithful chunks rather than compressing or omitting content. "
            . "STRICTLY FORBIDDEN to include words like \"Tags:\" or \"Questions:\" inside this field.";

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
        $targetChunkWords = $this->getTargetChunkWordCount();
        $softMinChunkWords = $this->getSoftChunkWordMinimum();
        $singleChunkThresholdWords = $this->getSingleChunkThresholdWords();
        $hardChunkWordLimit = $this->getHardChunkWordLimit();

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
- All chunk-size constraints below are expressed in WORDS only, never in characters.
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

--- CHUNKING RULES (CRITICAL) ---
14. TARGET SIZE: aim for approximately {$targetChunkWords} words per chunk.
15. SOFT MINIMUM: when possible, keep each chunk at or above {$softMinChunkWords} words.
16. You may go below {$softMinChunkWords} words ONLY when:
   - the visible text block is naturally shorter
   - a new major heading or separate artifact begins
   - the final remainder is shorter
   - forcing the minimum would require merging unrelated blocks or inventing glue text
17. STRICT MAXIMUM: a chunk MUST NEVER exceed {$hardChunkWordLimit} words. There are NO exceptions to this rule.
18. If the page text clearly exceeds {$singleChunkThresholdWords} words in total, you are FORBIDDEN from returning a single chunk. You MUST split it into multiple chunks.
19. Split priority for long text:
    (a) Paragraph boundary
    (b) Sentence punctuation boundary (e.g. period, question mark)
    (c) If a single paragraph is extremely long, split it mid-paragraph at the nearest sentence end before hitting the limit
    (d) If no sentence boundary exists before the limit, split at the nearest whitespace before exceeding {$hardChunkWordLimit} words
20. Do not compress or omit text to avoid splitting. If it's too long, split it.
21. Keep tags/questions OUT of chunk content.
22. NO visual/page-description language (e.g., "the image shows"). Ignore illustrations.
23. Do not duplicate information across chunks.
24. Do not copy previous continuity context into current chunk content.
25. Metadata handoff notes must be minimal.
26. If page has no readable text, return empty chunks.
27. Always start a new chunk when a new major heading or clearly separate text artifact begins.
{$indexingBlock}
{$extraInstructionsBlock}

### MOST CRITICAL CHUNKING DIRECTIVE
DO NOT OUTPUT GIANT CHUNKS.
DO NOT OUTPUT TINY CHUNKS.
Operate within this WORD-BASED window whenever possible:
- target: about {$targetChunkWords} words per chunk
- soft minimum: {$softMinChunkWords} words
- hard maximum: {$hardChunkWordLimit} words

If the total extracted text clearly exceeds {$singleChunkThresholdWords} words, YOU MUST CREATE MULTIPLE CHUNKS.
Use chunks shorter than {$softMinChunkWords} words only when the visible text block is naturally shorter, when a new heading/artifact begins, or when the final remainder would otherwise force merging unrelated blocks, omission, duplication, or invention.
Returning a single chunk for a whole dense page will cause a critical system failure.
RESPECT THE LIMITS.
INSTRUCTIONS;
    }

    protected function getTargetChunkWordCount(): int
    {
        return max(
            15,
            (int) ceil($this->preferredChunkLength / self::ESTIMATED_CHARS_PER_WORD)
        );
    }

    protected function getSingleChunkThresholdWords(): int
    {
        $threshold = (int) ceil(
            ($this->preferredChunkLength + 200) / self::ESTIMATED_CHARS_PER_WORD
        );

        return max($this->getTargetChunkWordCount() + 1, $threshold);
    }

    protected function getHardChunkWordLimit(): int
    {
        $hardLengthLimit = max(
            $this->preferredChunkLength + 300,
            (int) ceil($this->preferredChunkLength * 1.5)
        );

        return max(
            $this->getTargetChunkWordCount() + 1,
            (int) ceil($hardLengthLimit / self::ESTIMATED_CHARS_PER_WORD)
        );
    }

    protected function getSoftChunkWordMinimum(): int
    {
        return max(
            15,
            (int) floor($this->getTargetChunkWordCount() * 0.5)
        );
    }

    protected function getHardChunkLengthLimit(): int
    {
        return max(
            $this->preferredChunkLength + 300,
            (int) ceil($this->preferredChunkLength * 1.5)
        );
    }

    /**
     * @param array<int, mixed> $chunks
     * @return array{0: array<int, mixed>, 1: int}
     */
    protected function enforceHardChunkLimit(array $chunks): array
    {
        $hardChunkLengthLimit = $this->getHardChunkLengthLimit();
        $pendingChunks = array_values($chunks);
        $normalizedChunks = [];
        $splitCount = 0;

        while (!empty($pendingChunks)) {
            $chunk = array_shift($pendingChunks);
            if (!is_array($chunk)) {
                $normalizedChunks[] = $chunk;
                continue;
            }

            $content = is_string($chunk['content'] ?? null) ? trim($chunk['content']) : '';
            if ($content === '' || mb_strlen($content) <= $hardChunkLengthLimit) {
                $normalizedChunks[] = $chunk;
                continue;
            }

            [$firstPart, $secondPart] = $this->splitContentInTwo($content);
            if ($firstPart === '' || $secondPart === '' || $firstPart === $content || $secondPart === $content) {
                $normalizedChunks[] = $chunk;
                continue;
            }

            $firstChunk = $chunk;
            $firstChunk['content'] = $firstPart;

            $secondChunk = $chunk;
            $secondChunk['content'] = $secondPart;

            array_unshift($pendingChunks, $secondChunk, $firstChunk);
            $splitCount++;
        }

        return [$normalizedChunks, $splitCount];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitContentInTwo(string $content): array
    {
        $length = mb_strlen($content);
        if ($length <= 1) {
            return [$content, ''];
        }

        $midpoint = (int) floor($length / 2);
        $window = max((int) floor($length * 0.2), 80);
        $leftBound = max(1, $midpoint - $window);
        $rightBound = min($length - 1, $midpoint + $window);

        $bestSplitPos = null;
        $bestScore = null;

        for ($i = $leftBound; $i <= $rightBound; $i++) {
            $prev = mb_substr($content, $i - 1, 1);
            $curr = mb_substr($content, $i, 1);
            $boundaryRank = null;

            if ($prev === "\n" && $curr === "\n") {
                $boundaryRank = 3;
            } elseif (in_array($prev, ['.', '!', '?', ';', ':'], true) && preg_match('/\s/u', $curr) === 1) {
                $boundaryRank = 2;
            } elseif (preg_match('/\s/u', $curr) === 1) {
                $boundaryRank = 1;
            }

            if ($boundaryRank === null) {
                continue;
            }

            $distance = abs($i - $midpoint);
            $score = ($boundaryRank * 100000) - $distance;

            if ($bestSplitPos === null || $score > $bestScore) {
                $bestSplitPos = $i;
                $bestScore = $score;
            }
        }

        $splitPos = $bestSplitPos ?? $midpoint;
        $first = trim(mb_substr($content, 0, $splitPos));
        $second = trim(mb_substr($content, $splitPos));

        if ($first === '' || $second === '') {
            $splitPos = $midpoint;
            $first = trim(mb_substr($content, 0, $splitPos));
            $second = trim(mb_substr($content, $splitPos));
        }

        return [$first, $second];
    }

    public function respond(?string $message = null): string|array|DataModel|MessageInterface
    {
        $this->changeProvider($this->config['provider']);
        $this->model = $this->config['model'];

        if ($this->pageImageDataUrl !== '') {
            $this->withImages([$this->pageImageDataUrl]);
        }

        try {
            $response = parent::respond(
                "Transcribe and chunk this page image (page {$this->pageNumber}) in strict transcription-only mode."
            );
        } catch (TypeError $e) {
            throw new RuntimeException(
                'AI provider returned null content (transient error or refusal): ' . $e->getMessage(),
                0,
                $e
            );
        }

        $chunks = is_array($response['chunks'] ?? null) ? $response['chunks'] : [];
        [$chunks, $hardLimitSplitCount] = $this->enforceHardChunkLimit($chunks);

        $chunkPreviews = array_values(array_filter(array_map(
            static function (mixed $chunk): string {
                if (!is_array($chunk)) {
                    return '';
                }

                $content = is_string($chunk['content'] ?? null) ? $chunk['content'] : '';
                $normalized = preg_replace('/\s+/', ' ', trim($content)) ?? '';

                return Str::limit($normalized, 50);
            },
            $chunks
        )));

        $activeContext = is_string($response['active_context_for_next_chunking'] ?? null)
            ? trim($response['active_context_for_next_chunking'])
            : '';

        $usefulInfo = is_string($response['useful_info_for_next_chunking'] ?? null)
            ? trim($response['useful_info_for_next_chunking'])
            : '';

        Log::channel('document-queue')->debug('[ImagePostProcessingAgent] response summary', [
            'page_number' => $this->pageNumber,
            'hard_chunk_length_limit' => $this->getHardChunkLengthLimit(),
            'hard_limit_splits_applied' => $hardLimitSplitCount,
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
