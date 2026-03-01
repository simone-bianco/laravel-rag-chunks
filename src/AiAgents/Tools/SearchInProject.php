<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Tool;
use SimoneBianco\LaravelRagChunks\AiAgents\ProjectSearchAgent;

class SearchInProject extends Tool
{
    public function __construct(
        protected string $projectAlias,
        ?string $name = 'search_in_project',
        ?string $description = 'Search in a specific project'
    ) {
        parent::__construct($name, $description);
    }

    public function getProperties(): array
    {
        return [
            'project_alias' => [
                'type' => 'string',
                'description' => 'Alias of the project to search in',
            ],
            'search_query' => [
                'type' => 'string',
                'description' => 'A detailed search query to find relevant chunks',
            ],
        ];
    }

    protected array $required = ['project_alias', 'search_query'];

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    protected function handle(array|DataModel $input): mixed
    {
        return ProjectSearchAgent::make($input['project_alias'])
            ->respond($input['search_query'])
            ->getContent();
    }
}
