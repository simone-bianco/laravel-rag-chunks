<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Tool;
use SimoneBianco\LaravelRagChunks\AiAgents\ProjectSearchAgent;

class SearchInProject extends Tool
{
    public function __construct(
        protected string $projectAlias,
        protected ?string $documentAlias = null,
        ?string $name = 'search_in_project',
        ?string $description = 'Search the project knowledge base. Accepts multiple independent search queries that are executed in parallel in a single call. Use this to retrieve relevant information chunks from the project.'
    ) {
        parent::__construct($name, $description);
    }

    public function getProperties(): array
    {
        return [
            'persistentKey' => [
                'type' => 'string',
                'description' => "Reuse the same key across turns to preserve search-agent memory and refine results with feedback. Use a new random key to start a fresh search thread with no memory. Create a key with a random suffix of an alphanumeric 5-digits."
            ],
            'searches' => [
                'type'        => 'array',
                'description' => 'Array of 1-5 independent search queries executed in parallel in one call. Do not call this tool multiple times in the same turn.',
                'items'       => [
                    'type' => 'string',
                    'description' => 'Concise search query for one angle (e.g. "goblin tribe rituals", "founding of the empire").',
                ],
            ],
        ];
    }

    protected array $required = ['persistentKey', 'searches'];

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    protected function handle(array|DataModel $input): mixed
    {
        $data = is_array($input) ? $input : $input->toArray();
        $schema = $data;

        if (! is_array($schema) && method_exists($schema, 'toArray')) {
            $schema = $schema->toArray();
        }

        $schema = is_array($schema) ? $schema : [];
        $persistentKeyRaw = (string) ($schema['persistentKey'] ?? $data['persistentKey'] ?? Str::random());
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

        Log::channel('search')->info('[SearchInProject] Executing search tool', [
            'project' => $this->projectAlias,
            'document' => $this->documentAlias,
            'persistent_key' => $persistentKey,
            'searches_count' => count($searches),
        ]);

        if ($searches === []) {
            return ['results' => []];
        }

        $queryLines = collect($searches)
            ->map(function (array $s, int $i) {
                $query = json_encode($s['query'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return ($i + 1) . ". query={$query}";
            })
            ->join("\n");

        $result = new ProjectSearchAgent(
            $persistentKey,
            $this->projectAlias,
            $this->documentAlias
        )->respond("Search queries:\n$queryLines");

        return is_array($result) ? $result : $result->getContent();
    }
}
