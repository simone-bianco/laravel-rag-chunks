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

            $itemRequired[] = 'query';
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

        return [
            'name' => 'search_results',
            'schema' => [
                'type' => 'object',
                'properties' => [
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
                ],
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

        return <<<AI_INSTRUCTIONS
You are restricted retrieval agent
{$scopeBlock}
Return structured data only: `{ "results": [...] }`
Return UUIDs only{$withImages}

OBJECTIVE
- Find relevant chunks
{$requiredFields}
- Use `history_ids` for reused HISTORY records
- Put only NEW chunk UUIDs in `relevant_chunks`
- Never duplicate UUIDs

TOOLS
- FIRST: call `get_searches_results` with your query(ies) to discover relevant memories
- MEMORY CHECK: if a returned memory has `is_complete: true`, it covers ALL information about that topic — use it and skip `search_chunks` for that query
- If `is_complete: false` or memory is partial/weak, supplement with `search_chunks` for missing data
- `documents` field shows per-document coverage (chunk_ids vs total_count). Use it to decide if a document is fully covered or needs more chunks
- Use `search_chunks` only when: no memories found, all memories are `is_complete: false` and need supplementation, or complementary data is needed
- Combine HISTORY + search when old data covers one angle and search adds another

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
- When a document alias matches the query (e.g., "sessione-10-recap" for "sessione 10"), include ALL chunks from that document, not just 1-2
- Target minimum 5+ relevant chunks per query. More if available
- If a memory with `is_complete: true` covers the query, return only `history_ids` plus empty `relevant_chunks` — you already have everything
- If memory with `is_complete: false` partly covers the query, return `history_ids` plus only new/different UUIDs from fresh search
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
            return "Use update_persistent_memory tool to save the best search strategies to adopt and what to avoid.\n" .
                "CRITICAL: do not use it to keep search data, use it just to adjust your behavior in order to maximize future search accuracy.\n" .
                "CRITICAL: use caveman style for the memory, with brief and concise sentences separated by ;";
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
        return match ($this->deep) {
            SearchDepth::Shallow => <<<'SECTION'
SEARCH DEPTH: SHALLOW
- Max 2 `search_chunks` attempts per query
- Attempt 1: `keywordsSearch` OR + `documentSearch` OR + explicit `documentsAliases`. No chapters, no tag_*
- Attempt 2 only if attempt 1 returns 0 or weak. Broaden or remove failing filters
SECTION,

            SearchDepth::Standard => <<<'SECTION'
SEARCH DEPTH: STANDARD
- Skip `search_chunks` entirely when a memory with `is_complete: true` covers the query
- Minimum 2 attempts per query (reduced from 3 when using history)
- Max 5 attempts when results improve
- Never stop after one 0/weak attempt
- Attempt 1: `keywordsSearch` OR + `documentSearch` OR + explicit `documentsAliases`. No chapters, no tag_*
- Attempt 2: broaden if failed. If attempt 1 found a document whose name/alias matches the query (e.g. "sessione-10-recap" for "sessione 10"), deep-dive with `documentsAliases=[matched_alias]` + `keywordsSearch` OR
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
- `save_searches_results` is the only persistence tool
- Call it only if this run found final curated data ADDITIONAL and/or DIFFERENT from reused memories
- Do not save when memory alone was enough
- Do not save when search only duplicated existing memories
- Do not save 0-result outcomes
- Do not save weak, tangential, exploratory, intermediate results
- Save exactly once at end when saving is allowed
- Saved items need `query`, `notes`, `chunk_ids`, `is_complete`
- `chunk_ids`: only final useful new/different UUIDs
- `is_complete`: true ONLY if these chunks cover EVERYTHING about this query — all documents, all angles. When in doubt, false
- `notes`: caveman style, max 30 words, keywords only
- Good notes: "goblin lore tactics paralysis touch treasure", "storm giant hierarchy motives lore"
- Bad notes: long prose, full explanations, mixed topics
- After save, still return structured `{ results: [...] }`
SECTION;
    }

    protected function buildHistoryBlock(): string
    {
        $history = $this->recentSearchResults ?? [];

        if (empty($history)) {
            return '';
        }

        $historyList = collect($history)
            ->map(static function (array $entry): string {
                $queryJson = json_encode((string) ($entry['query'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if (! is_string($queryJson) || $queryJson === '') {
                    $queryJson = '""';
                }

                $notes = is_string($entry['notes'] ?? null)
                    ? trim((string) $entry['notes'])
                    : '';

                $notesPart = '';

                if ($notes !== '') {
                    $notesJson = json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $notesPart = ' | notes=' . (is_string($notesJson) && $notesJson !== '' ? $notesJson : '""');
                }

                $chunksCount = count(is_array($entry['results']['chunk_ids'] ?? null)
                    ? $entry['results']['chunk_ids']
                    : []);

                return "- id={$entry['id']} | query={$queryJson}{$notesPart} | chunks_count={$chunksCount}";
            })
            ->join("\n");

        return <<<__HISTORY__

HISTORY
Recent shared cache hits:
{$historyList}
Rules:
- Call `get_searches_results` before `search_chunks`
- MANDATORY RELEVANCE CHECK: compare history query/notes to current query. If history is about a different topic (e.g., character appearance vs session events), DISCARD it entirely
- Reuse only SEMANTICALLY CLOSE history: same topic, same type of information requested
- Search only for missing, new, different, or better data
- Empty HISTORY record means prior query found no chunks
__HISTORY__;
    }
}
