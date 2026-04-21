<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools\Memory;

use LarAgent\Core\Contracts\DataModel;
use LarAgent\Tool;
use SimoneBianco\LaravelAiAgents\Models\AiAgent;
use SimoneBianco\LaravelRagChunks\Services\SearchResultService;

class GetSearchesResultsTool extends Tool
{
    public function __construct(
        protected ?string $agentId = null,
        protected ?string $projectId = null,
        ?string $name = 'get_searches_results',
        ?string $description = 'Given the identifiers of N previous searches from this agent history, return the full results of those searches (same output shape as the search tool).',
    ) {
        parent::__construct($name, $description);
    }

    public function getProperties(): array
    {
        return [
            'searchResultIds' => [
                'type'        => 'array',
                'description' => 'Array of SearchResult IDs (UUIDs) to retrieve, taken from the HISTORY block injected in the instructions.',
                'items'       => [
                    'type'        => 'string',
                    'description' => 'SearchResult UUID.',
                ],
            ],
        ];
    }

    protected array $required = ['searchResultIds'];

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    protected function handle(array|DataModel $input): mixed
    {
        $data   = is_array($input) ? $input : $input->toArray();
        $schema = is_array($data) ? $data : [];

        $ids = $schema['searchResultIds'] ?? [];
        if (! is_array($ids) || empty($ids)) {
            return ['error' => 'searchResultIds cannot be empty'];
        }

        if (empty($this->agentId)) {
            return ['error' => 'Calling agent is not configured for search-result history'];
        }

        if (empty($this->projectId)) {
            return ['error' => 'Project context is not configured for search-result history'];
        }

        $agent = AiAgent::find($this->agentId);
        if (! $agent) {
            return ['error' => "Agent {$this->agentId} not found"];
        }

        $ids = array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? trim($v) : null,
            $ids,
        )));

        $stored = SearchResultService::forAgent($agent)->getSearchResults($ids, (string) $this->projectId);

        return [
            'results' => array_values(array_filter(array_map(
                static fn (array $row): ?array => is_array($row['results'] ?? null) ? $row['results'] : null,
                $stored,
            ))),
        ];
    }
}
