<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing;

use Illuminate\Support\Facades\Log;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use RuntimeException;
use SimoneBianco\LaravelAiAgents\Agents\RotableAgent;
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
    protected int $processedChunksCount = 0;
    protected int $totalDocumentChunks = 0;

    public function __construct(
        string $key,
        array $injectConfig = [],
    ) {
        parent::__construct($key);

        $config = config('rag_chunks.agents.postprocessor', []);
        $this->config['provider'] = $injectConfig['provider'] ?? $config['provider'] ?? 'openai';
        $this->config['model'] = $injectConfig['model'] ?? $config['model'] ?? 'gpt-5.4-mini';
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

    public function withProcessedChunksCount(int $count): self
    {
        $this->processedChunksCount = max(0, $count);

        Log::channel('document-queue')->debug('[PostProcessingAgent] withProcessedChunksCount', [
            'processed_chunks_count' => $this->processedChunksCount,
        ]);

        return $this;
    }

    public function withTotalDocumentChunks(int $count): self
    {
        $this->totalDocumentChunks = max(0, $count);

        Log::channel('document-queue')->debug('[PostProcessingAgent] withTotalDocumentChunks', [
            'total_document_chunks' => $this->totalDocumentChunks,
        ]);

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
            $contentDescription = 'Clean, format, summarize extractively: fix encoding/OCR/line breaks, delete lower-value sentences first, avoid factual rewrites. Preserve stats, numbers, entities, technical terms. Never include "Tags:" or "Questions:"';
        } elseif ($this->cleanText) {
            $contentDescription = 'Clean and format only: fix encoding/OCR/line breaks. Never rephrase, condense, omit, or change meaning. Preserve entities exactly. Never include "Tags:" or "Questions:"';
        } else {
            $contentDescription = 'Exact source text with formatting repair only: fix encoding, garbled chars, mid-sentence line breaks. Never omit, condense, rephrase, or change meaning. Never include "Tags:" or "Questions:"';
        }

        $contentDescription .= ' Fidelity: never invent entities, objects, characters, events, relationships, mechanics. Never swap entity type. Preserve polarity, intent, numbers, DCs, dice, units, constraints, flavor details, quotes/slogans. Keep separate artifacts separate';

        if ($this->contextInjection) {
            $contentDescription .= ' May prepend brief context only for highly abstract data';
        } else {
            $contentDescription .= ' No meta-commentary like "These chunks describe", "Here is", "This text covers". Start with source text';
        }

        $allowedFigurePaths = array_values(array_filter(array_unique(array_map(function (array $chunk): string {
            return isset($chunk['figure_path']) && is_string($chunk['figure_path'])
                ? trim($chunk['figure_path'])
                : '';
        }, $this->chunks))));

        Log::channel('document-queue')->debug('POSTPROCESSING SETTINGS', [
            'summarization' => $this->summarization,
            'cleanText' => $this->cleanText,
            'contextInjection' => $this->contextInjection,
        ]);

        return PostProcessingResponseSchemaFactory::build(
            contentDescription: $contentDescription,
            tagsByType: $this->tagsByType,
            createIndex: $this->createIndex,
            allowedFigurePaths: $allowedFigurePaths,
        );
    }

    public function instructions(): string
    {
        $contextRule = $this->contextInjection
            ? "7. CONTEXT: For highly abstract data (isolated table etc), prepend brief clarifying context to `content`"
            : "7. NO META: Never start `content` with phrases like \"These chunks describe\". Start with raw text";

        if ($this->cleanText && $this->summarization) {
            $summarizationRule = "1. CLEAN+FORMAT+SUMMARIZE: repair text/OCR/line breaks and compress extractively. Remove less relevant parts first. Rewrite only for OCR repair. Never remove entities, proper nouns, technical terms, numbers, code";
            $chunkingModeRules = "- Do NOT output 1:1 input mapping\n- Separate distinct topics; isolate explanation/table/etc when distinct\n- Aggressive split: if topic exceeds target, split into logical sub-chunks. Never create massive blocks. Never cut sentences";
        } elseif ($this->cleanText) {
            $summarizationRule = "1. CLEAN+FORMAT ONLY: fix encoding, garbled chars, mid-sentence breaks, OCR noise. Never condense, rephrase, omit. Preserve meaning exactly";
            $chunkingModeRules = "- Conservative mode: 1:1 allowed/preferred when chunks are coherent\n- Merge/split only for readability, sentence integrity, or size\n- Do not restructure for style";
        } else {
            $summarizationRule = "1. FORMAT REPAIR ONLY: never alter meaning or condense. Join artificial line breaks, repair unicode/encoding, fix clear OCR typos. All information survives";
            $chunkingModeRules = "- Conservative mode: 1:1 allowed/preferred when chunks are coherent\n- Merge/split only for readability, sentence integrity, or size\n- Do not restructure for style";
        }

        $extraInstructionsBlock = !empty($this->extraInstruction)
            ? "\nEXTRA INSTRUCTIONS\n{$this->extraInstruction}\n"
            : "";

        $boundaryBlock = '';
        if ($this->batchContextBefore !== null || $this->batchContextAfter !== null) {
            $boundaryBlock = "\nBATCH BOUNDARY CONTEXT - awareness only, never reproduce\n";
            $boundaryBlock .= "This batch is part of a larger document. Edge snippets help continuity only. They are NOT input and MUST NOT appear in `content`\n";
            if ($this->batchContextBefore !== null) {
                $boundaryBlock .= "Before: `{$this->batchContextBefore}`\n";
            }
            if ($this->batchContextAfter !== null) {
                $boundaryBlock .= "After: `{$this->batchContextAfter}`\n";
            }
        }

        $iterativeUsefulInfoBlock = '';
        if ($this->usefulInfoFromPreviousChunking !== null) {
            $iterativeUsefulInfoBlock = "\nPREVIOUS BATCH HANDOFF\n{$this->usefulInfoFromPreviousChunking}\n";
        }

        $indexingBlock = '';
        if ($this->createIndex) {
            $existingIndex = !empty($this->currentIndex)
                ? implode(', ', $this->currentIndex)
                : 'none';
            $currentBatchSize = count($this->chunks);
            $totalConsidered = $this->processedChunksCount + $currentBatchSize;
            $isSmallDocument = $this->totalDocumentChunks > 0 && $this->totalDocumentChunks <= 200;
            $indexGranularityRules = $isSmallDocument
                ? "- Small doc: avoid collapsing everything into one chapter\n- Use multiple titles when topics/sections are clearly distinct\n- Reuse existing title only on strong semantic fit\n- Preserve meaningful boundaries while minimizing titles"
                : "- Standard doc: minimize title count while staying correct\n- Reuse same title when fit is reasonable\n- New title only when reuse is clearly wrong";
            $indexingBlock = "\nDYNAMIC INDEXING ENABLED\nAssign one `chapter_title` to every output chunk\n\nPROGRESS\n- Processed before batch: {$this->processedChunksCount}\n- Current batch input chunks: {$currentBatchSize}\n- Total considered: {$totalConsidered}\n- Current index titles: {$existingIndex}\n\nINDEX RULES\n- Goal is clustering, not per-chunk summaries\n- Same broader section => exact same chapter title\n- Do not create a new title for narrower wording/sub-points\n- Prefer broad accurate section titles over narrow titles\n- Use existing index for naming consistency when useful\n- Titles: short, clear, specific, section-level\n- No vague titles: Miscellaneous, Other, Notes, General\n- Do not include document name\n- Natural words only, no snake_case/kebab-case\n{$indexGranularityRules}\n\nINDEX OUTPUT\n- Exactly one `chapter_title` per output chunk\n- Do not omit chunks or add extra properties\n- Existing index can guide likely document topics\n";
        }

        $estimatedWords = (int)($this->preferredChunkLength / 6);

        $currentActiveContext = $this->activeContextFromPreviousChunking ?? 'none';
        $contextBlock = "CURRENT ACTIVE CONTEXT\n{$currentActiveContext}\n";

        return <<<INSTRUCTIONS
PERSONA
Expert RAG data processor and dynamic chunker for any text source: docs, manuals, code, books

$this->documentContext

$contextBlock

GOAL + DYNAMIC CHUNKING
Clean, merge, or split raw chunks into cohesive atomic semantic units
- $chunkingModeRules
- TARGET SIZE: Maximum $estimatedWords words per chunk. You can stretch a LITTLE bit this limit in order to NOT cut in the middle of sentences, BUT you can cut paragraphs and sections if they exceed the target limit by too much
- AVOID ENORMOUS CHUNKS: NEVER create monolithic chunks!

CRITICAL CONTENT RULES
0. Priority: content fidelity > entities/literals/relationships > formatting/OCR repair > chunk optimization > tags/questions
$summarizationRule
2. NO DATA LOSS: process ALL input. Do not stop early, omit paragraphs/sections, or lose information
3. PRESERVE ENTITIES: keep items, variables, names, domain terms
4. PRESERVE FORMATTING: while fixing OCR breaks, keep intentional newlines (`\n`), tabs, list/table/code spacing
4.1 AMBIGUITY: if unsure, keep original wording; do not normalize/infer
4.2 OCR SCOPE: fix only obvious mechanical OCR/encoding defects; never reinterpret semantics
5. NO METADATA IN TEXT: tags/questions only in arrays, never in `content`
5.1 CONTENT FIRST: metadata derives from `content`; metadata never shapes/distorts `content`
6. SUBJECT IN METADATA: tags and every question must name the main subject/entity; no pronouns like "it"/"they"
$contextRule
8. ANTI-INVENTION: add nothing absent from source. If unsure, keep source wording
9. NO SEMANTIC FLIPS: never invert role/relation/sentiment: ally/rival, support/threat, optional/mandatory, invitation/summons
10. TECHNICAL LITERALS: keep numbers, DCs, dice (`3d10`), distances, durations, requirements, constraints unchanged
11. VOICE: preserve tone when present: ironic, theatrical, dark humor, etc
12. SELF-CHECK: before JSON, remove broken/fused token artifacts, orphan fragments, collisions. If unsure, keep original local wording
13. DETAIL COMPLETENESS: keep setup hooks, sensory details, named roles/traits, procedural prep details
14. QUOTES/SLOGANS: preserve readable direct speech/slogans; avoid paraphrase
15. DOCUMENT BOUNDARIES: keep distinct artifacts distinct; do not fuse letters/flyers/side notes/handouts with narration unless source does
16. AMBIGUOUS MARKERS: `(4)` may be map/label marker, not count; reinterpret only if source says so
17. REFERENCE SAFETY: resolve conservatively; unclear antecedent => keep wording, do not guess entity

FIGURES
Preserve `figure_path`. If merging chunks with different figures, keep most relevant or split to preserve both. Return "" if none

ITERATIVE HANDOFF
- Always return `active_context_for_next_chunking`
- Short section/chapter continuity label for next batch
- Reuse prior context while valid; change only on real context shift
- No useful context => ""
- Always return `useful_info_for_next_chunking`
- Short practical hints: unfinished sentence, truncated list/table, expected continuation, unresolved reference
- Only directly observed boundary facts from current input; never infer future content
- If no following text context, return "" unless current batch clearly ends with true truncation
- Concise and actionable; nothing useful => ""

CHUNKS OUTPUT
- Dirty chunks (meaningless non-UTF8 chars, mid-sentence newlines) => clean, well formatted output; remove useless dirt/newlines
- Extremely dirty chunks may be repaired to intended text, e.g. "chapter4chapterdirtystuff towers" => "chapter 4 - towers"

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
