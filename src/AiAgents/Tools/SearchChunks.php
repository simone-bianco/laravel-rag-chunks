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
//        $tagsByType = $this->project->getTagsSlugsKeyedByTypes();
//        $tagsProperties = [];
//        foreach ($tagsByType as $type => $tags) {
//            $tagsProperties[$type] = [
//                'type' => 'array',
//                'items' => [
//                    'type' => 'enum',
//                    'description' => 'List of tags of type "' . $type . '"',
//                    'enum' => $tags,
//                ],
//            ];
//        }
        return [
            'page' => [
                'type' => 'integer',
                'description' => 'Page number',
            ],
            'perPage' => [
                'type' => 'integer',
                'description' => 'Number of results per page',
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
        ];
    }

    protected array $required = ['page', 'perPage'];

    protected function handle(array|DataModel $input): mixed
    {
        $this->logger()->debug('[Tool] SearchChunks called', ['data' => $input]);

        $data = !is_array($input) ? $input->toArray() : $input;
        $data['projectsAliases'] = [$this->project->alias];

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
            'last_page'    => $searchResults['last_page'] ?? null,
            'data'         => Arr::mapWithKeys($searchResults['data'] ?? [], fn ($item) => [
                $item['id'] => ChunkMapper::mapItem($item),
            ]),
        ]);

        $this->logger()->debug('[Tool] SearchChunks returned', ['data' => $searchResults]);

        return $searchResults;
    }
}
