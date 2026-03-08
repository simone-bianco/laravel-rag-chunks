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
                'description' => "CRITICAL: Hard filter for category '{$alias}'. Look closely at the available enum values.",
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
                'description' => 'If set, only chunks belonging to documents with chosen aliases will be taken',
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
                'description' => 'Number of results per page, use 5 by default',
                'enum' => [3, 5, 10],
            ],
            'keywordsSearch' => [
                'type' => 'array',
                'description' => 'Hard filter: only chunks containing ALL these words are returned. NEVER use on the first search call — only use on follow-up calls if initial results are too broad.',
                'items' => [
                    'type' => 'string',
                ],
            ],
            'chunksAliases' => [
                'type' => 'array',
                'description' => 'If set, only chunks with chosen aliases will be taken',
                'items' => [
                    'type' => 'string',
                ],
            ],
            'textSearch' => [
                'type' => 'string',
                'description' => 'Core semantic meaning of the query, e.g. "Goblins and their history"',
            ],
            'questionsSearch' => [
                'type' => 'string',
                'description' => 'If the user asks a direct question, repeat it here to match pre-indexed questions, e.g. "What do goblins eat?"',
            ],
            'semanticTagsSearch' => [
                'type' => 'string',
                'description' => 'Comma-separated semantic tags, e.g. "goblin,history,lair"',
            ],
        ], $tagProperties, $documentsAliasesProperties);
    }

    protected function handle(array|DataModel $input): mixed
    {
        $data = $this->normalizeInput($input);

        $tagFilters = $this->resolveTagFilters($data);
        if (!empty($tagFilters)) {
            $data['chunkTagGroups'] = $tagFilters;
        }

        $this->logger()->debug('[Tool] SearchChunks called', ['data' => $data]);

        $dto = ChunkSearchDataDTO::fromArray($data);
        $results = $this->formatResults($this->chunkService->search($dto)->toArray());

        $this->logger()->debug('[Tool] SearchChunks returned', ['data' => $this->truncateForLog($results)]);

        return $results;
    }

    /**
     * Converts DataModel to array and injects project/document aliases.
     */
    private function normalizeInput(array|DataModel $input): array
    {
        $data = ! is_array($input) ? $input->toArray() : $input;
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
        $chunkTagGroups = [];
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'tag_') && is_array($value) && count($value) > 0) {
                $alias = substr($key, 4);
                $tagType = TagType::query()
                    ->where('project_id', $this->project->id)
                    ->where('alias', $alias)
                    ->first();
                if ($tagType) {
                    $ids = Tag::query()
                        ->where('tag_type_id', $tagType->id)
                        ->whereIn('slug', $value)
                        ->pluck('id')
                        ->toArray();
                    if (! empty($ids)) {
                        $chunkTagGroups[$alias] = $ids;
                    }
                }
                unset($data[$key]);
            }
        }

        return $chunkTagGroups;
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
