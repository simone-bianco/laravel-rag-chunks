<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools\Memory;

use Illuminate\Support\Facades\Log;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Tool;
use Psr\Log\LoggerInterface;
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

    public function logger(): LoggerInterface
    {
        return Log::channel('search');
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
            $this->logger()->warning('[GetSearchesResultsTool] Empty lookup request', [
                'agent_id' => $this->agentId,
                'project_id' => $this->projectId,
            ]);

            return ['error' => 'searchResultIds cannot be empty'];
        }

        if (empty($this->agentId)) {
            $this->logger()->warning('[GetSearchesResultsTool] Missing agent context');

            return ['error' => 'Calling agent is not configured for search-result history'];
        }

        if (empty($this->projectId)) {
            $this->logger()->warning('[GetSearchesResultsTool] Missing project context', [
                'agent_id' => $this->agentId,
            ]);

            return ['error' => 'Project context is not configured for search-result history'];
        }

        $agent = AiAgent::find($this->agentId);
        if (! $agent) {
            $this->logger()->warning('[GetSearchesResultsTool] Agent not found', [
                'agent_id' => $this->agentId,
                'project_id' => $this->projectId,
            ]);

            return ['error' => "Agent {$this->agentId} not found"];
        }

        $this->logger()->info('[GetSearchesResultsTool] Lookup requested', [
            'agent_id' => (string) $agent->id,
            'project_id' => (string) $this->projectId,
            'requested_count' => count($ids),
        ]);

        $ids = array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? trim($v) : null,
            $ids,
        )));

        $stored = SearchResultService::forAgent($agent)->getSearchResults($ids, (string) $this->projectId);

        $results = array_values(array_filter(array_map(
            static fn (array $row): ?array => is_array($row['results'] ?? null) ? $row['results'] : null,
            $stored,
        )));

        $this->logger()->info('[GetSearchesResultsTool] Lookup completed', [
            'agent_id' => (string) $agent->id,
            'project_id' => (string) $this->projectId,
            'requested_count' => count($ids),
            'resolved_count' => count($results),
            'resolved_ids' => array_values(array_map(
                static fn (array $row): string => (string) ($row['id'] ?? ''),
                $stored,
            )),
        ]);

        return [
            'results' => $results,
        ];
    }
}
