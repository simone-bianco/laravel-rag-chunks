<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Tool;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\DTOs\ChunkSearchDataDTO;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Project;
use SimoneBianco\LaravelRagChunks\Services\ChunkService;
use SimoneBianco\LaravelSimpleTags\Tag;
use SimoneBianco\LaravelSimpleTags\TagType;

/**
 * Executes a hybrid RAG search (semantic + keyword + tag filters) against the chunk store.
 *
 * Returns to the agent a payload grouped by document alias:
 * @example
 * [
 *   'current_page' => 1,
 *   'last_page'    => 3,
 *   'data'         => [
 *     'goblin-lore' => [
 *       'description' => 'Everything about goblin culture and society',
 *       'chunks'      => [
 *         'a1b2c3d4-...' => [
 *           'content'    => 'The goblin tribe gathers every full moon...',
 *           'image_url'  => 'https://cdn.example.com/img/goblin-ritual.jpg',
 *           'relations'  => [
 *             ['chunk_id' => 'e5f6-...', 'name' => 'mentioned in: Warchief biography'],
 *           ],
 *           'prev_chunk' => ['id' => 'f0e9-...', 'preview' => 'Elder Gragnok rose to power af'],
 *           'next_chunk' => ['id' => 'b8c7-...', 'preview' => 'The ritual ends at dawn when t'],
 *         ],
 *       ],
 *     ],
 *   ],
 * ]
 *
 * - Keys of `data` are document aliases.
 * - Keys of `chunks` are chunk UUIDs — use these as chunk IDs in the agent response.
 * - `prev_chunk` / `next_chunk` are null when there is no adjacent chunk.
 */
class SearchChunks extends Tool
{
    protected ChunkService $chunkService;

    public function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    protected array $tagsByType = [];

    public function __construct(
        protected Project $project,
        protected ?Document $document = null,
        ?string $name = 'search_chunks',
        ?string $description = 'RAG search chunks'
    ) {
        $this->tagsByType = TagType::query()
            ->where('project_id', $this->project->id)
            ->where('ai_search', true)
            ->with(['tags' => fn ($q) => $q->select('id', 'tag_type_id', 'slug', 'name')])
            ->get()
            ->mapWithKeys(fn ($tagType) => [
                $tagType->alias => $tagType->tags->map(fn ($t) => $t->slug)->filter()->values()->toArray(),
            ])
            ->filter(fn ($slugs) => count($slugs) > 0)
            ->toArray();

        $this->chunkService = app(ChunkService::class);
        parent::__construct($name, $description);
    }

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    protected array $required = ['page', 'perPage'];

    public function getProperties(): array
    {
        $tagProperties = [];
        foreach ($this->tagsByType as $alias => $slugs) {
            $tagProperties["tag_{$alias}"] = [
                'type' => 'array',
                'description' => "CRITICAL HARD FILTER for category '{$alias}'. REFINEMENT ONLY: never send this on first attempt; use only on second attempt when first attempt is weak/empty and you need disambiguation.",
                'items' => [
                    'type' => 'string',
                    'enum' => $slugs,
                ],
            ];
        }

        $documentsAliasesProperties = [];
        if (empty($this->document)) {
            $documentsAliasesProperties['documentsAliases'] = [
                'type' => 'array',
                'description' => 'Document hard scope. REFINEMENT ONLY: never set on first attempt unless the user explicitly asks to restrict to specific document aliases. Never guess aliases: use only explicit user-provided aliases or aliases surfaced by previous search results. On retry, combine with at most one deterministic filter family.',
                'items' => ['type' => 'string'],
            ];
        }

        return array_merge([
            'page' => [
                'type' => 'integer',
                'description' => 'Page number',
            ],
            'perPage' => [
                'type' => 'integer',
                'description' => 'Number of results per page. Use 10 by default. Allowed values: 8, 10, 12.',
                'enum' => [8, 10, 12],
            ],
            'keywordsSearch' => [
                'type' => 'object',
                'description' => 'Hard lexical filter: only chunks containing these substrings are returned. NEVER use on the first search call — refinement/fallback only. Each value is a case-insensitive substring match, not necessarily a full word. You may and should use partial words, stems, or robust lexical fragments when useful (e.g. "ross" to catch "rosso"/"rossi"). Prefer the language of the document rather than blindly using English. You may also try multiple likely document languages when useful.',
                'properties' => [
                    'keywords' => [
                        'type' => 'array',
                        'description' => 'List of keywords to filter',
                        'items' => [
                            'type' => 'string'
                        ]
                    ],
                    'mode' => [
                        'type' => 'string',
                        'description' => 'Filter mode',
                        'enum' => ['OR', 'AND']
                    ]
                ],
                'required' => ['keywords', 'mode']
            ],
            'chapters' => [
                'type' => 'array',
                'description' => 'REFINEMENT ONLY: never use on first attempt. Use only from attempt 2 with chapter aliases discovered in prior results. Never guess chapter aliases. Apply OR semantics and combine with at most one deterministic filter family.',
                'items' => [
                    'type' => 'string',
                    'description' => 'Alias of the chapter'
                ]
            ],
            'textSearch' => [
                'type' => 'string',
                'description' => 'ALWAYS provide this. Core semantic meaning of the query, compressed into 3-5 English concept keywords, e.g. "goblin tribal elder ceremony".',
            ],
            'questionsSearch' => [
                'type' => 'string',
                'description' => 'ALWAYS provide this, even when the user input is not a literal question. If the user asks a direct question, repeat/restyle it here. Otherwise put a short natural-language retrieval phrase, pseudo-question, or query-like sentence to improve matching against pre-indexed questions. This field is mandatory in practice because it improves recall and accuracy.',
            ],
            'semanticTagsSearch' => [
                'type' => 'string',
                'description' => 'ALWAYS provide this. Comma-separated English semantic concepts/tags, e.g. "goblin,history,lair". Mandatory in practice for every search because it improves thematic recall, even for exact entity lookups.',
            ],
            'hasImage' => [
                'type' => 'string',
                'description' => 'Visual filter. Allowed values: "with", "without", "mixed". IMPORTANT: use "mixed" by default. Use "with" only when the query explicitly asks for images, maps, diagrams, screenshots, illustrations, or other visual material. Use "without" only when the user explicitly asks to exclude images or asks for text-only results. Never omit this field; default is "mixed".',
                'enum' => ['with', 'without', 'mixed'],
            ],
            'allowRelaxTagFilters' => [
                'type' => 'boolean',
                'description' => 'Optional safety valve for low recall with chunk tag filters. If true and first-page results are below perPage while chunkTagGroups are active, tool may retry once without chunk tag filters.',
            ],
        ], $tagProperties, $documentsAliasesProperties);
    }

    protected function handle(array|DataModel $input): mixed
    {
        $data = $this->normalizeInput($input);
        $relaxedTagFiltersApplied = false;
        $deferredTagFiltersOnFirstPass = false;

        $resolvedTagFilters = $this->resolveTagFilters($data);
        $tagFilters = $resolvedTagFilters['ids'];
        $tagFiltersSlugs = $resolvedTagFilters['slugs'];

        $canApplyTagFilters = $this->isRefinementRequest($data);

        if (! empty($tagFilters) && $canApplyTagFilters) {
            $data['chunkTagGroups'] = $tagFilters;
            $data['_chunkTagGroupsSlugsForLog'] = $tagFiltersSlugs;
        } elseif (! empty($tagFilters)) {
            $deferredTagFiltersOnFirstPass = true;
            $data['_deferredChunkTagGroupsSlugsForLog'] = $tagFiltersSlugs;
        }

        $logData = $this->prepareDataForLog($data);
        $this->logger()->debug('[Tool] SearchChunks called', ['data' => $logData]);

        $primaryRawResults = $this->searchRaw($data);
        $results = $this->formatResults($primaryRawResults);

        if ($this->shouldRelaxTagFilters($data, $primaryRawResults)) {
            $relaxedData = $data;
            unset($relaxedData['chunkTagGroups']);
            unset($relaxedData['_chunkTagGroupsSlugsForLog']);

            $this->logger()->info('[Tool] SearchChunks retry without chunkTagGroups due low recall', [
                'project' => $this->project->alias,
                'document' => $this->document?->alias,
                'query' => $relaxedData['textSearch'] ?? null,
            ]);

            $relaxedRawResults = $this->searchRaw($relaxedData);

            if (! empty($relaxedRawResults['data'] ?? [])) {
                $mergedRawResults = $this->mergeImageResults(
                    $primaryRawResults,
                    $relaxedRawResults,
                    (int) ($data['perPage'] ?? 10)
                );

                $results = $this->formatResults($mergedRawResults);
                $relaxedTagFiltersApplied = true;
            }
        }

        if ($relaxedTagFiltersApplied) {
            $results['_meta'] = [
                'relaxed_chunk_tag_groups' => true,
            ];
        }

        if ($deferredTagFiltersOnFirstPass) {
            $results['_meta'] = array_merge($results['_meta'] ?? [], [
                'deferred_chunk_tag_groups' => true,
            ]);
        }

        $this->logger()->debug('[Tool] SearchChunks returned', ['data' => $this->truncateForLog($results)]);

        return $results;
    }

    private function searchRaw(array $data): array
    {
        $dto = ChunkSearchDataDTO::fromArray($data);

        return $this->chunkService->search($dto)->toArray();
    }

    /**
     * Converts DataModel to array and injects project/document aliases.
     */
    private function normalizeInput(array|DataModel $input): array
    {
        $data = ! is_array($input) ? $input->toArray() : $input;

        if (array_key_exists('has_image', $data) && ! array_key_exists('hasImage', $data)) {
            $data['hasImage'] = $data['has_image'];
            unset($data['has_image']);
        }

        $data['projectsAliases'] = [$this->project->alias];

        if ($this->document) {
            $data['documentsAliases'] = [$this->document->alias];
        }

        return $data;
    }

    /**
     * Resolves tag_* keys into chunkTagGroups (typeAlias => tagIds[]).
     * Removes tag_* keys from $data.
     */
    private function resolveTagFilters(array &$data): array
    {
        $chunkTagGroupsIds = [];
        $chunkTagGroupsSlugs = [];

        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'tag_') && is_array($value) && count($value) > 0) {
                $alias = substr($key, 4);
                $slugs = array_values(array_filter(array_map(
                    static fn ($slug) => is_string($slug) ? trim($slug) : null,
                    $value
                )));

                $tagType = TagType::query()
                    ->where('project_id', $this->project->id)
                    ->where('alias', $alias)
                    ->first();

                if ($tagType) {
                    $ids = Tag::query()
                        ->where('tag_type_id', $tagType->id)
                        ->whereIn('slug', $slugs)
                        ->pluck('id')
                        ->toArray();

                    if (! empty($ids)) {
                        $chunkTagGroupsIds[$alias] = $ids;
                        $chunkTagGroupsSlugs[$alias] = $slugs;
                    }
                }

                unset($data[$key]);
            }
        }

        return [
            'ids' => $chunkTagGroupsIds,
            'slugs' => $chunkTagGroupsSlugs,
        ];
    }

    private function shouldRelaxTagFilters(array $data, array $rawPaginator): bool
    {
        $requestedPerPage = max(1, (int) ($data['perPage'] ?? 10));
        $resultCount = count($rawPaginator['data'] ?? []);

        return ($data['allowRelaxTagFilters'] ?? false) === true
            && ! empty($data['chunkTagGroups'])
            && ((int) ($data['page'] ?? 1) === 1)
            && $resultCount < $requestedPerPage;
    }

    private function isRefinementRequest(array $data): bool
    {
        $hasKeywords = ! empty($data['keywordsSearch']['keywords'] ?? []);
        $hasChapters = ! empty($data['chapters'] ?? []);

        return $hasKeywords || $hasChapters;
    }

    private function prepareDataForLog(array $data): array
    {
        $logData = $data;

        if (isset($logData['_chunkTagGroupsSlugsForLog'])) {
            $logData['chunkTagGroups'] = $logData['_chunkTagGroupsSlugsForLog'];
            unset($logData['_chunkTagGroupsSlugsForLog']);
        }

        if (isset($logData['_deferredChunkTagGroupsSlugsForLog'])) {
            $logData['deferredChunkTagGroups'] = $logData['_deferredChunkTagGroupsSlugsForLog'];
            unset($logData['_deferredChunkTagGroupsSlugsForLog']);
        }

        return $logData;
    }

    /**
     * Merge strict + relaxed image result sets preserving strict-first ordering
     * and deduplicating by chunk id, capped to requested page size.
     */
    private function mergeImageResults(array $strictRaw, array $relaxedRaw, int $limit): array
    {
        $limit = max(1, $limit);

        $merged = [];
        $seen = [];

        foreach ([($strictRaw['data'] ?? []), ($relaxedRaw['data'] ?? [])] as $items) {
            foreach ($items as $item) {
                $id = $item['id'] ?? null;

                if (! is_string($id) || $id === '' || isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $merged[] = $item;

                if (count($merged) >= $limit) {
                    break 2;
                }
            }
        }

        $strictRaw['data'] = $merged;

        return $strictRaw;
    }

    /**
     * Formats raw paginator output into clean array grouped by document alias.
     */
    private function formatResults(array $rawPaginator): array
    {
        $byDocument = [];

        foreach ($rawPaginator['data'] ?? [] as $item) {
            $docAlias = $item['document']['alias'] ?? ($item['document_id'] ?? 'unknown');

            if (! isset($byDocument[$docAlias])) {
                $byDocument[$docAlias] = [
                    'description' => $item['document']['description'] ?? null,
                    'chunks'      => [],
                ];
            }

            $byDocument[$docAlias]['chunks'][$item['id']] = ChunkMapper::mapItem($item);
        }

        return array_filter([
            'current_page' => $rawPaginator['current_page'] ?? null,
            'last_page'    => $rawPaginator['last_page'] ?? null,
            'data'         => $byDocument,
        ]);
    }

    /**
     * Truncates long string values for logging purposes.
     */
    private function truncateForLog(array $results): array
    {
        $logResults = $results;

        if (is_array($logResults)) {
            array_walk_recursive($logResults, function (&$value, $key) {
                if (is_string($value) && in_array($key, ['content', 'questions', 'semantic_tags'])) {
                    $value = Str::limit(preg_replace('/\s+/', ' ', trim($value)), 100);
                } elseif (is_string($value) && strlen($value) > 150) {
                    $value = Str::limit(preg_replace('/\s+/', ' ', trim($value)), 150);
                }
            });
        }

        return $logResults;
    }
}
