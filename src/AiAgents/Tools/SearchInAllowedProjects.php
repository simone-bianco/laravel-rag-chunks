<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Tool;
use SimoneBianco\LaravelRagChunks\AiAgents\SearchAgent;
use SimoneBianco\LaravelRagChunks\AiAgents\SearchScope;
use SimoneBianco\LaravelRagChunks\Enums\SearchDepth;
use SimoneBianco\LaravelRagChunks\Enums\SearchScopeType;

class SearchInAllowedProjects extends Tool
{
    /**
     * @var array<string, string>
     */
    protected array $allowedAliasLookup = [];

    public function __construct(
        protected array $allowedProjectAliases,
        protected bool $includeImages = true,
        protected ?string $model = null,
        protected SearchDepth $deep = SearchDepth::Standard,
        ?string $name = 'search_in_project',
        ?string $description = 'Search inside one allowed project. You must provide projectAlias + 1-5 searches. Searches run in parallel in a single call.',
        protected bool $historyEnabled = false,
    ) {
        $this->allowedProjectAliases = array_values(array_unique(array_filter(array_map(
            static fn ($alias) => is_string($alias) ? trim($alias) : '',
            $allowedProjectAliases,
        ))));

        foreach ($this->allowedProjectAliases as $alias) {
            $this->allowedAliasLookup[$this->normalizeAliasKey($alias)]       = $alias;
            $this->allowedAliasLookup[$this->normalizeAliasKey('#' . $alias)] = $alias;
            $this->allowedAliasLookup[$this->normalizeAliasKey('@' . $alias)] = $alias;
        }

        parent::__construct($name, $description);
    }

    public function getProperties(): array
    {
        return [
            'projectAlias' => [
                'type'        => 'string',
                'description' => 'Target project alias to search in.',
                'enum'        => $this->allowedProjectAliases,
            ],
            'persistentKey' => [
                'type'        => 'string',
                'description' => 'Reuse same key for same research thread. New 5-char alphanumeric suffix for new thread.',
            ],
            'searches' => [
                'type'        => 'array',
                'description' => 'Array of 1-5 independent search queries executed in parallel in one call.',
                'items'       => [
                    'type'        => 'string',
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
        $data   = is_array($input) ? $input : $input->toArray();
        $schema = is_array($data) ? $data : [];

        $rawProjectAlias = isset($schema['projectAlias']) && is_string($schema['projectAlias'])
            ? trim($schema['projectAlias'])
            : '';

        $projectAlias = $this->resolveAllowedAlias($rawProjectAlias);

        if ($projectAlias === null) {
            throw new InvalidArgumentException(
                'projectAlias is not allowed. Allowed aliases: ' . implode(', ', $this->allowedProjectAliases)
            );
        }

        $persistentKeyRaw = (string) ($schema['persistentKey'] ?? Str::random());
        $persistentKey    = str_starts_with($persistentKeyRaw, 'project_search:')
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
            'project'        => $projectAlias,
            'persistent_key' => $persistentKey,
            'searches_count' => count($searches),
        ]);

        $queryLines = collect($searches)
            ->map(function (array $s, int $i) {
                $query = json_encode($s['query'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return ($i + 1) . ". query={$query}";
            })
            ->join("\n");

        $scope  = new SearchScope($projectAlias, SearchScopeType::Project);
        $result = new SearchAgent(
            key: $persistentKey,
            scope: $scope,
            includeImages: $this->includeImages,
            model: $this->model,
            deep: $this->deep,
            historyEnabled: $this->historyEnabled,
        )->respond("Search queries:\n$queryLines");

        return is_array($result) ? $result : $result->getContent();
    }

    private function resolveAllowedAlias(string $alias): ?string
    {
        if ($alias === '') {
            return null;
        }

        return $this->allowedAliasLookup[$this->normalizeAliasKey($alias)] ?? null;
    }

    private function normalizeAliasKey(string $alias): string
    {
        $normalized = trim($alias);

        while ($normalized !== '' && ($normalized[0] === '#' || $normalized[0] === '@')) {
            $normalized = substr($normalized, 1);
        }

        return Str::lower(trim($normalized));
    }
}
