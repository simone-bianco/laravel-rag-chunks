<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Tool;
use SimoneBianco\LaravelAiAgents\Concerns\ExposesEditableParameters;
use SimoneBianco\LaravelRagChunks\AiAgents\ProjectSearchAgent;

class SearchInAllowedProjects extends Tool
{
    use ExposesEditableParameters;

    public function editableParameters(): array
    {
        return [
            [
                'name' => 'projectAlias',
                'type' => 'string',
                'description' => 'Allowed project alias to search in.',
                'default' => null,
                'overridable' => false,
                'variable_bindable' => false,
                'toggleable' => false,
                'default_enabled' => true,
            ],
            [
                'name' => 'persistentKey',
                'type' => 'string',
                'description' => 'Persistent key for thread continuity.',
                'default' => null,
                'overridable' => false,
                'variable_bindable' => false,
                'toggleable' => false,
                'default_enabled' => true,
            ],
            [
                'name' => 'searches',
                'type' => 'array',
                'description' => 'Array of 1-5 parallel search queries.',
                'default' => null,
                'overridable' => false,
                'variable_bindable' => false,
                'toggleable' => false,
                'default_enabled' => true,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $allowedProjectAliases
     */
    public function __construct(
        protected array $allowedProjectAliases,
        ?string $name = 'search_in_project',
        ?string $description = 'Search inside one allowed project. You must provide projectAlias + 1-5 searches. Searches run in parallel in a single call.'
    ) {
        $this->allowedProjectAliases = array_values(array_unique(array_filter(array_map(
            static fn ($alias) => is_string($alias) ? trim($alias) : '',
            $allowedProjectAliases,
        ))));

        parent::__construct($name, $description);
    }

    public function getProperties(): array
    {
        return [
            'projectAlias' => [
                'type' => 'string',
                'description' => 'Target project alias to search in.',
                'enum' => $this->allowedProjectAliases,
            ],
            'persistentKey' => [
                'type' => 'string',
                'description' => 'Reuse same key for same research thread. New 5-char alphanumeric suffix for new thread.',
            ],
            'searches' => [
                'type' => 'array',
                'description' => 'Array of 1-5 independent search queries executed in parallel in one call.',
                'items' => [
                    'type' => 'string',
                    'description' => 'Concise query for one angle.',
                ],
            ],
        ];
    }

    protected array $required = ['projectAlias', 'persistentKey', 'searches'];

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    protected function handle(array|DataModel $input): mixed
    {
        $data = is_array($input) ? $input : $input->toArray();
        $schema = is_array($data) ? $data : [];

        $projectAlias = isset($schema['projectAlias']) && is_string($schema['projectAlias'])
            ? trim($schema['projectAlias'])
            : '';

        if ($projectAlias === '' || ! in_array($projectAlias, $this->allowedProjectAliases, true)) {
            throw new InvalidArgumentException(
                'projectAlias is not allowed. Allowed aliases: ' . implode(', ', $this->allowedProjectAliases)
            );
        }

        $persistentKeyRaw = (string) ($schema['persistentKey'] ?? Str::random());
        $persistentKey = str_starts_with($persistentKeyRaw, 'project_search:')
            ? $persistentKeyRaw
            : 'project_search:' . $persistentKeyRaw;

        $searches = collect($schema['searches'] ?? [])
            ->map(function ($search): ?array {
                if (is_string($search)) {
                    $query = trim($search);

                    return $query !== '' ? ['query' => $query] : null;
                }

                if (! is_array($search)) {
                    return null;
                }

                $query = isset($search['query']) && is_string($search['query'])
                    ? trim($search['query'])
                    : '';

                return $query !== '' ? ['query' => $query] : null;
            })
            ->filter()
            ->values()
            ->all();

        if ($searches === []) {
            return ['results' => []];
        }

        Log::channel('search')->info('[SearchInAllowedProjects] Executing search tool', [
            'project' => $projectAlias,
            'persistent_key' => $persistentKey,
            'searches_count' => count($searches),
        ]);

        $queryLines = collect($searches)
            ->map(function (array $s, int $i) {
                $query = json_encode($s['query'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return ($i + 1) . ". query={$query}";
            })
            ->join("\n");

        $result = new ProjectSearchAgent($persistentKey, $projectAlias, null)
            ->respond("Search queries:\n$queryLines");

        return is_array($result) ? $result : $result->getContent();
    }
}
