<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Tool;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\DTOs\ChunkSearchDataDTO;
use SimoneBianco\LaravelRagChunks\Models\Project;
use SimoneBianco\LaravelRagChunks\Services\ChunkService;
use SimoneBianco\LaravelSimpleTags\Tag;
use SimoneBianco\LaravelSimpleTags\TagType;

class SearchChunks extends Tool
{
    protected ChunkService $chunkService;

    public function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    public function __construct(
        protected Project $project,
        ?string $name = 'search_chunks',
        ?string $description = 'RAG search chunks'
    ) {
        $this->chunkService = app(ChunkService::class);
        parent::__construct($name, $description);
    }

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    public function getProperties(): array
    {
        $tagsByType = TagType::query()
            ->where('project_id', $this->project->id)
            ->where('ai_search', true)
            ->with(['tags' => fn ($q) => $q->select('id', 'tag_type_id', 'slug', 'name')])
            ->get()
            ->mapWithKeys(fn ($tagType) => [
                $tagType->alias => $tagType->tags->map(fn ($t) => $t->slug)->filter()->values()->toArray(),
            ])
            ->filter(fn ($slugs) => count($slugs) > 0)
            ->toArray();

        $tagProperties = [];
        foreach ($tagsByType as $alias => $slugs) {
            $tagProperties["tag_{$alias}"] = [
                'type' => 'array',
                'description' => "CRITICAL: Hard filter for category '{$alias}'. Look closely at the available enum values.",
                'items' => [
                    'type' => 'string',
                    'enum' => $slugs,
                ],
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
                'enum' => [5, 10, 15],
            ],
            'keywordsSearch' => [
                'type' => 'array',
                'description' => 'Hard filter: only chunks containing ALL these words are returned. NEVER use on the first search call — only use on follow-up calls if initial results are too broad.',
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
        ], $tagProperties);
    }

    protected array $required = ['page', 'perPage'];

    protected function handle(array|DataModel $input): mixed
    {
        $this->logger()->debug('[Tool] SearchChunks called', ['data' => $input]);

        $data = ! is_array($input) ? $input->toArray() : $input;
        $data['projectsAliases'] = [$this->project->alias];

        // Resolve tag_* properties (typeAlias => slugs[]) into chunkTagGroups (typeAlias => tagIds[])
        $tagTypeModel = config('tags.tag_type_model', TagType::class);
        $chunkTagGroups = [];
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'tag_') && is_array($value) && count($value) > 0) {
                $alias = substr($key, 4);
                $tagType = $tagTypeModel::query()
                    ->where('project_id', $this->project->id)
                    ->where('alias', $alias)
                    ->first();
                if ($tagType) {
                    $tagModel = config('tags.tag_model', Tag::class);
                    $ids = $tagModel::query()
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
        if (! empty($chunkTagGroups)) {
            $data['chunkTagGroups'] = $chunkTagGroups;
        }

        $chunkSearchDto = ChunkSearchDataDTO::fromArray($data);
        $searchResults = $this->chunkService->search($chunkSearchDto)->toArray();

        if (! $chunkSearchDto->includePageUrls) {
            unset(
                $searchResults['first_page_url'],
                $searchResults['last_page_url'],
                $searchResults['next_page_url'],
                $searchResults['prev_page_url'],
                $searchResults['path'],
                $searchResults['links'],
            );
        }

        $searchResults = array_filter([
            'current_page' => $searchResults['current_page'] ?? null,
            'last_page' => $searchResults['last_page'] ?? null,
            'data' => Arr::mapWithKeys($searchResults['data'] ?? [], fn ($item) => [
                $item['id'] => ChunkMapper::mapItem($item),
            ]),
        ]);

        $logResults = $searchResults;
        if (is_array($logResults)) {
            array_walk_recursive($logResults, function (&$value, $key) {
                if (is_string($value) && in_array($key, ['content', 'questions', 'semantic_tags'])) {
                    $value = \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', trim($value)), 100);
                } elseif (is_string($value) && strlen($value) > 150) {
                    $value = \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', trim($value)), 150);
                }
            });
        }

        $this->logger()->debug('[Tool] SearchChunks returned', ['data' => $logResults]);

        return $searchResults;
    }
}
