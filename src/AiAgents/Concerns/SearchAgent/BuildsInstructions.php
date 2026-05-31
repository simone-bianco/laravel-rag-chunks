<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Concerns\SearchAgent;

use SimoneBianco\LaravelRagChunks\Enums\SearchDepth;
use SimoneBianco\LaravelRagChunks\Enums\SearchScopeType;

trait BuildsInstructions
{
    abstract protected function searchResultsActive(): bool;

    abstract protected function persistentMemoryActive(): bool;

    abstract protected function resolveProjectTagsFromScope(): array;

    abstract protected function resolveProjectIdFromScope(): ?string;

    protected function buildResponseSchema(): array
    {
        $itemRequired = [];
        $itemProperties = [];
        $rootProperties = [];

        if ($this->searchResultsActive()) {
            $itemProperties['query'] = [
                'type' => 'string',
                'description' => 'One sentence summary of the search and covered data',
            ];

            $itemProperties['history_ids'] = [
                'type' => 'array',
                'description' => 'Optional UUIDs of reused memory records from get_searches_results. Use instead of repeating their chunk UUIDs',
                'items' => [
                    'type' => 'string',
                    'description' => 'SearchResult UUID from memory lookup',
                ],
            ];

            $itemProperties['is_complete'] = [
                'type' => 'boolean',
                'description' => 'True ONLY when these chunks cover EVERYTHING knowable about this query. Default false. Set true when a document alias perfectly matches the query (e.g. "api-reference-v2" for "API v2 reference") AND you returned ALL chunks and total_count confirm full coverage. Also true if topic is exhaustively covered across multiple documents. False in all other cases — even when you think most info is covered.',
            ];

            $itemRequired[] = 'query';

            // Root-level persistence control
            $rootProperties['store_mode'] = [
                'type' => 'string',
                'enum' => ['none', 'as_is', 'split'],
                'description' => 'none=skip persistence (history sufficient or query too specific); as_is=save single memory; split=save multiple focused memories from one composite query',
            ];

            $rootProperties['store_context'] = [
                'type' => 'string',
                'description' => 'When store_mode=split: short instructions for the split agent (e.g. "split into 3: authentication, rate limiting, error handling"). Empty otherwise.',
            ];
        }

        $itemProperties['relevant_chunks'] = [
            'type' => 'array',
            'description' => 'NEW chunk UUIDs from search_chunks. Do not repeat chunks covered by history_ids',
            'items' => [
                'type' => 'string',
                'description' => 'Chunk UUID copied from search result keys',
            ],
        ];
        $itemRequired[] = 'relevant_chunks';

        if ($this->includeImages) {
            $itemProperties['relevant_images'] = [
                'type' => 'array',
                'description' => 'Images from relevant chunks',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => 'Image URL'],
                        'content' => ['type' => 'string', 'description' => 'Short image description'],
                    ],
                    'required' => ['url', 'content'],
                    'additionalProperties' => false,
                ],
            ];
            $itemRequired[] = 'relevant_images';
        }

        $properties = $rootProperties + [
            'results' => [
                'type' => 'array',
                'description' => 'One item per input query, same order',
                'items' => [
                    'type' => 'object',
                    'properties' => $itemProperties,
                    'required' => $itemRequired,
                    'additionalProperties' => false,
                ],
            ],
        ];

        return [
            'name' => 'search_results',
            'schema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => ['results'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    public function instructions(): string
    {
        $scopeBlock = $this->buildScopeBlock();
        $imagePolicy = $this->buildImagePolicy();
        $scopeTagsBlock = $this->buildScopeTagsBlock();
        $searchRetryBlock = $this->buildSearchRetryBlock();
        $searchResultsMemoryBlock = $this->buildSearchResultsMemoryBlock();
        $persistentMemoryBlock = $this->buildPersistentMemoryBlock();

        $withImages = $this->includeImages ? ' + images' : '';
        $requiredFields = $this->includeImages
            ? '- Output each item with `relevant_chunks` and `relevant_images`'
            : '- Output each item with `relevant_chunks`';

        $imageOutputPolicy = $this->includeImages
            ? '- Add images only when present and relevant'
            : '- Ignore images. Do not output image fields';

        $searchResultsActive = $this->searchResultsActive();

        // Conditional blocks — only included when search results are enabled
        $objectiveMemoryLines = $searchResultsActive
            ? "- Use `history_ids` for reused memory records\n- Put only NEW chunk UUIDs in `relevant_chunks`\n- Never duplicate UUIDs\n- Set `store_mode` + `store_context` to control persistence (see SEARCH MEMORY below)"
            : '';

        $toolsMemoryBlock = $searchResultsActive
            ? <<<'TOOLS_MEM'
- FIRST: call `get_searches_results` with your query(ies) to discover relevant memories
- MEMORY CHECK: if a returned memory has `is_complete: true`, it covers ALL information about that topic — use it and skip `search_chunks` for that query
- If `is_complete: false` or memory is partial/weak, supplement with `search_chunks` for missing data
- `documents` field shows per-document coverage (chunk_ids vs total_count). Use it to decide if a document is fully covered or needs more chunks
- Use `search_chunks` only when: no memories found, all memories are `is_complete: false` and need supplementation, or complementary data is needed
- Combine memory + search when old data covers one angle and search adds another
TOOLS_MEM
            : '';

        $resultMemoryLines = $searchResultsActive
            ? "- Set `store_mode` and `store_context` at the root level alongside `results`\n- If a memory with `is_complete: true` covers the query, return only `history_ids` plus empty `relevant_chunks` — you already have everything\n- If memory with `is_complete: false` partly covers the query, return `history_ids` plus only new/different UUIDs from fresh search"
            : '';

        return <<<AI_INSTRUCTIONS
You are restricted retrieval agent
{$scopeBlock}
Return structured data only: `{ "results": [...] }`
Return UUIDs only{$withImages}

OBJECTIVE
- Find relevant chunks
{$requiredFields}
{$objectiveMemoryLines}

TOOLS
{$toolsMemoryBlock}
- Use `search_chunks` as the primary search tool

SEARCH CALL BASE
- Always send: `page`, `perPage`, `textSearch`, `questionsSearch`, `semanticTagsSearch`, `hasImage`, `allowRelaxTagFilters`, `tags`
- `perPage`: default 10
- `allowRelaxTagFilters`: true
{$imagePolicy}
{$scopeTagsBlock}

FIRST ATTEMPT
- First `search_chunks` attempt: ONLY use `keywordsSearch` (OR mode) + `documentSearch` (OR mode) + explicit `documentsAliases` from user
- `documentSearch`: OR only, keywords matched on document title/name/description. Use to screen documents
- `keywordsSearch`: object `{ keywords: [...], mode: "OR"|"AND" }`. Prefer OR first
- NEVER send `chapters` on first attempt. Chapters are aliases found in prior results, not guessable
- NEVER send `tag_*` on first attempt. These are refinement-only and need pairing with keywords/chapters
- `documentsAliases`: ONLY use when user explicitly names a document or constructor already scoped to one
- NEVER guess document aliases
- If nothing to filter, use semantic-only broad search

MULTILINGUAL KEYWORDS
- For `keywordsSearch` and `documentSearch`, include likely Italian + English variants when useful
- OR examples: `["tavolo","table"]`, `["porta","door"]`, `["mappa","map"]`
- Prefer single words/stems/fragments. Avoid long phrases
- Use document language when known

CONSTRAINTS
- Parse inline constraints from user text:
  `documentsAliases=[...]`
  `documentSearch={keywords:[...]}`
  `chapters=[...]`
  `keywords=[...]`
  `keywordMode=OR|AND`
  `hasImage=...`
  `tag_<typeAlias>=[...]`
- Inline constraints override inference
- Translate `keywords` + `keywordMode` to `keywordsSearch`
- `documentSearch` always has `mode: "OR"` even if omitted
- Explicit user constraints are authoritative

ZERO / WEAK RESULTS
- 0 results = failed attempt
- Weak = less than 3 useful chunks or tangential
- After failed/weak attempt, retry with changed strategy
- If strict filters caused failure, remove or loosen them
- Broaden languages, stems, synonyms, semantic text, documentSearch terms
- Keep useful UUIDs from all attempts

{$searchRetryBlock}

RESULT EXTRACTION
- Collect relevant chunk UUIDs from all useful attempts
{$resultMemoryLines}
- When a document alias matches the query (e.g., "api-reference-v2" for "API v2 reference"), deep-dive with `documentsAliases=[matched_alias]` + `keywordsSearch` to find the best chunks inside it. NEVER blindly include ALL chunks of a large document — documents can contain thousands of chunks covering many unrelated topics
- Target minimum 5+ relevant chunks per query. More if available
{$imageOutputPolicy}

{$searchResultsMemoryBlock}
{$persistentMemoryBlock}

LANGUAGE
- Semantic fields may be English
- Lexical fields may be Italian, English, or both

CRITICAL
- Return many relevant chunks
- Exclude only alien chunks
- No prose outside JSON
AI_INSTRUCTIONS;
    }

    protected function buildScopeBlock(): string
    {
        return match ($this->scope->type) {
            SearchScopeType::Project => "Scope project: {$this->scope->alias}",
            SearchScopeType::Document => "Scope document: {$this->scope->alias}",
        };
    }

    protected function buildPersistentMemoryBlock(): string
    {
        if ($this->persistentMemoryActive()) {
            return <<<'SECTION'
PERSISTENT MEMORY
- Use `update_persistent_memory` to save reusable SEARCH STRATEGIES, not search results
- Focus on parameter optimization: tags, textSearch, questionsSearch, semanticTagsSearch, documentSearch patterns
- NEVER save per-query details or specific document references (unless doc has 500+ chunks or multiple docs share the same topic — then brief filter tip only)
- Style: caveman, punchy, no full sentences. One entry per line, separated by ;
- NO blank lines between entries. Each line = one strategy tip
- Format examples: "recaps -> deep dive for named npcs; session docs -> documentSearch sessione+number; avoid tag filters first pass; broaden textSearch keywords on weak results"
- MANDATORY: skip saving when you have nothing new to add — only save genuinely useful patterns
SECTION;
        }

        return '';
    }

    protected function buildImagePolicy(): string
    {
        if (! $this->includeImages) {
            return '- `hasImage`: always `without`';
        }

        return '- `hasImage`: default `mixed`; use `with` only for explicit visual requests; use `without` only for explicit text-only requests';
    }

    protected function buildScopeTagsBlock(): string
    {
        $resolvedTags = $this->resolveProjectTagsFromScope();
        $tagsJson = json_encode($resolvedTags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($tagsJson === false) {
            $tagsJson = '{}';
        }

        return <<<TAGS
- `tags` mandatory every `search_chunks` call
- Pass exactly this map: {$tagsJson}
- If empty, pass `tags: {}`
TAGS;
    }

    protected function buildSearchRetryBlock(): string
    {
        $historyActive = $this->searchResultsActive();
        $standardHistoryLine = $historyActive
            ? "- Skip `search_chunks` entirely when a memory with `is_complete: true` covers the query\n"
            : '';

        return match ($this->deep) {
            SearchDepth::Shallow => <<<'SECTION'
SEARCH DEPTH: SHALLOW
- Max 2 `search_chunks` attempts per query
- Attempt 1: `keywordsSearch` OR + `documentSearch` OR + explicit `documentsAliases`. No chapters, no tag_*
- Attempt 2 only if attempt 1 returns 0 or weak. Broaden or remove failing filters
SECTION,

            SearchDepth::Standard => <<<SECTION
SEARCH DEPTH: STANDARD
{$standardHistoryLine}- Minimum 2 attempts per query (reduced from 3 when using history)
- Max 5 attempts when results improve
- Never stop after one 0/weak attempt
- Attempt 1: `keywordsSearch` OR + `documentSearch` OR + explicit `documentsAliases`. No chapters, no tag_*
- Attempt 2: broaden if failed. If attempt 1 found a document whose name/alias matches the query (e.g. "api-reference" for "API reference"), deep-dive with `documentsAliases=[matched_alias]` + `keywordsSearch` OR
- Attempt 3+: if deep-dive successful, broaden to adjacent documents. Add `chapters` from found results. Try one `tag_*` paired with keywords/chapters. Alternate multilingual keywords
- Stop only when results are dense (8+ relevant chunks) and precise
SECTION,

            SearchDepth::Deep => <<<'SECTION'
SEARCH DEPTH: DEEP
- Minimum 5 attempts per query unless HISTORY fully answers
- Max 7 attempts
- Never stop after 1-2 weak attempts
- Attempt 1: `keywordsSearch` OR + `documentSearch` OR + explicit `documentsAliases`. No chapters, no tag_*
- Attempt 2: deep-dive on best matching document aliases from attempt 1 with `documentsAliases` + `keywordsSearch` OR
- Attempt 3+: broaden to adjacent docs, add `chapters` from results, try one `tag_*` paired with keywords, alternate languages/stems
- Merge all useful UUIDs; keep earlier findings
SECTION,
        };
    }

    protected function buildSearchResultsMemoryBlock(): string
    {
        if (! $this->searchResultsActive()) {
            return '';
        }

        return <<<'SECTION'
SEARCH MEMORY
- No tool call needed to save — the server persists results based on store_mode
- store_mode decision:
  - none: skip persistence. Use when HISTORY fully covers the query, or the query is too specific (e.g. "GDP comparison between Italy and Germany across 100 nations" — unlikely to repeat)
  - as_is: save all results as one memory. Use for focused queries (e.g. "JWT authentication setup", "error handling patterns") where all chunks belong to one topic
  - split: save as multiple focused memories. Use for composite queries (e.g. "info on authentication, rate limiting, and error handling") where chunks mix unrelated topics
- store_context (only for split): short instructions for the split agent (e.g. "split into 3 groups: authentication, rate limiting, error handling"). Max 100 chars, imperative style
- is_complete: true ONLY when these chunks cover EVERYTHING knowable about this query. Criteria:
  - A document alias perfectly matches the query (e.g. "api-reference-v2" for "API v2 reference") AND you returned ALL chunks from that document (chunk_ids count equals documents[alias].total_count)
  - OR the topic is exhaustively covered across multiple documents and no other relevant data exists
  - Default to false in all other cases — even when most info seems covered. Future agents rely on this flag to skip unnecessary searches
SECTION;
    }

    /**
     * @deprecated Removed — history injection was replaced by GetSearchesResultsTool.
     *   The LLM discovers memories via tool call, not via prompt injection.
     */
    protected function buildHistoryBlock(): string
    {
        return '';
    }
}
