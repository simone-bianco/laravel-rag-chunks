<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Tool;
use SimoneBianco\LaravelRagChunks\Models\Project;

class SearchChunks extends Tool
{
    public function __construct(
        protected Project $project,
        ?string $name = 'search_chunks',
        ?string $description = 'RAG search chunks'
    ) {
        parent::__construct($name, $description);
    }

    public function getProperties(): array
    {
        $tagsByType = $this->project->getTagsSlugsKeyedByTypes();
        $tagsProperties = [];
        foreach ($tagsByType as $type => $tags) {
            $tagsProperties[$type] = [
                'type' => 'array',
                'items' => [
                    'type' => 'enum',
                    'description' => 'List of tags of type "' . $type . '"',
                    'enum' => $tags,
                ],
            ];
        }
        return [
            'page' => [
                'type' => 'integer',
                'description' => 'Page number',
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Number of results per page',
                'enum' => [5, 10, 15],
            ],
            'keywords' => [
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                    'description' => 'Single keyword, e.g. goblin, laravel, revenue',
                ],
            ],
            'text' => [
                'type' => 'string',
                'description' => 'Text to search for, e.g. "Goblins and their history"',
            ],
            'questions' => [
                'type' => 'string',
                'description' => 'Questions to search for, e.g. "What is the revenue?" or "What do goblins eat?"',
            ],
            'semantic_tags' => [
                'type' => 'string',
                'description' => 'A list of semantic tags separated by comma, e.g. "goblin,history,lair", "laravel,php,controllers"',
            ],
            'tags' => [
                'type' => 'object',
                'description' => 'A list of tags by type that hard filter the search, excluding chunks that do not have those tags; use only for drastic filtering',
                'properties' => $tagsProperties,
            ],
            'required' => [
                'page',
                'per_page',
                'keywords',
                'text',
                'questions',
                'semantic_tags',
                'tags',
            ],
            'additionalProperties' => false,
        ];
    }

    protected array $required = ['location'];

    protected function handle(array|DataModel $input): mixed
    {

    }
}
