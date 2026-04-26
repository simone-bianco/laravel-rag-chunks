<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use Illuminate\Console\Application;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\Pool;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\SerializableClosure\SerializableClosure;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Tool;
use SimoneBianco\LaravelRagChunks\AiAgents\SearchAgent;
use SimoneBianco\LaravelRagChunks\AiAgents\SearchScope;
use SimoneBianco\LaravelRagChunks\Enums\SearchDepth;

class SearchTool extends Tool
{
    /**
     * @param  SearchScope[]  $scopes
     */
    public function __construct(
        protected array $scopes,
        protected bool $includeImages = true,
        protected ?string $model = null,
        protected SearchDepth $deep = SearchDepth::Standard,
        protected int $maxParallel = 8,
        ?string $name = 'search_in_project',
        ?string $description = 'Search the knowledge base. Accepts multiple independent search queries executed in parallel in a single call. Use this to retrieve relevant information chunks.',
        protected bool $historyEnabled = false,
    ) {
        $this->maxParallel = max(1, min(8, $this->maxParallel));
        parent::__construct($name, $description);
    }

    public function getProperties(): array
    {
        $availableScopeAliases = array_values(array_unique(array_map(
            static fn (SearchScope $scope): string => $scope->alias,
            $this->scopes,
        )));

        $aliasHint = $availableScopeAliases !== []
            ? implode(', ', $availableScopeAliases)
            : '(none)';

        return [
            'persistentKey' => [
                'type'        => 'string',
                'description' => 'Reuse the same key across turns to preserve search-agent memory and refine results with feedback. Use a new random key to start a fresh search thread with no memory. Create a key with a random alphanumeric 5-char suffix.',
            ],
            'searches' => [
                'type'        => 'array',
                'description' => "Array of 1-$this->maxParallel independent search queries executed in parallel in one call. Each search can optionally target specific scopes. Do not call this tool multiple times in the same turn.",
                'items'       => [
                    'type'        => 'object',
                    'description' => 'One search query with optional per-query scope targeting.',
                    'properties'  => [
                        'query' => [
                            'type'        => 'string',
                            'description' => 'Concise search query for one angle (e.g. "goblin tribe rituals", "founding of the empire").',
                        ],
                        'scopeAliases' => [
                            'type'        => 'array',
                            'description' => 'Optional: target only these scope aliases for this query. If omitted or empty, the query runs in all selected scopes.',
                            'items'       => [
                                'type' => 'string',
                                'description' => 'Scope alias to target.',
                            ],
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            'scopeAliases' => [
                'type' => 'array',
                'description' => "Select only the relevant aliases for this request (do not search all scopes by default). Available aliases: {$aliasHint}",
                'items' => [
                    'type' => 'string',
                    'description' => 'Alias of a relevant scope to include in this search execution.',
                ],
            ],
        ];
    }

    protected array $required = ['persistentKey', 'searches', 'scopeAliases'];

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    protected function handle(array|DataModel $input): mixed
    {
        $data   = is_array($input) ? $input : $input->toArray();
        $schema = is_array($data) ? $data : [];

        $persistentKeyRaw = (string) ($schema['persistentKey'] ?? Str::random());
        $persistentKey    = str_starts_with($persistentKeyRaw, 'project_search:')
            ? $persistentKeyRaw
            : 'project_search:' . $persistentKeyRaw;

        $searches = collect($schema['searches'] ?? [])
            ->map(function ($search): ?array {
                if (is_string($search)) {
                    $query = trim($search);

                    return $query !== '' ? ['query' => $query, 'scopeAliases' => []] : null;
                }

                if (! is_array($search)) {
                    return null;
                }

                $query = isset($search['query']) && is_string($search['query'])
                    ? trim($search['query'])
                    : '';

                if ($query === '') {
                    return null;
                }

                $scopeAliases = [];
                if (isset($search['scopeAliases']) && is_array($search['scopeAliases'])) {
                    $scopeAliases = array_values(array_filter(array_map(
                        static fn ($v) => is_string($v) ? trim($v) : null,
                        $search['scopeAliases'],
                    )));
                }

                return ['query' => $query, 'scopeAliases' => $scopeAliases];
            })
            ->filter()
            ->values()
            ->all();

        if ($searches === []) {
            Log::channel('search')->warning('[SearchTool] No search queries provided by tool-call input', [
                'persistent_key' => $persistentKey,
                'input_keys' => array_values(array_unique(array_keys($schema))),
                'raw_input' => $schema,
                'scopes_count' => count($this->scopes),
            ]);

            return [
                'results' => [],
                'diagnostics' => [
                    'status' => 'missing_searches',
                    'message' => 'No searches were provided in tool input.',
                ],
            ];
        }

        if (empty($this->scopes)) {
            Log::channel('search')->warning('[SearchTool] No scopes configured for search tool', [
                'persistent_key' => $persistentKey,
                'searches_count' => count($searches),
            ]);

            return [
                'results' => [],
                'diagnostics' => [
                    'status' => 'no_scopes',
                    'message' => 'No scopes configured for search tool.',
                ],
            ];
        }

        $requestedScopeAliases = collect($schema['scopeAliases'] ?? [])
            ->map(static fn ($alias): ?string => is_string($alias) ? trim($alias) : null)
            ->filter(static fn (?string $alias): bool => $alias !== null && $alias !== '')
            ->unique()
            ->values()
            ->all();

        $scopesToSearch = $requestedScopeAliases === []
            ? $this->scopes
            : array_values(array_filter(
                $this->scopes,
                static fn (SearchScope $scope): bool => in_array($scope->alias, $requestedScopeAliases, true),
            ));

        if ($requestedScopeAliases !== [] && $scopesToSearch === []) {
            Log::channel('search')->warning('[SearchTool] Invalid scopeAliases selection', [
                'requested_scope_aliases' => $requestedScopeAliases,
                'available_scope_aliases' => array_values(array_unique(array_map(
                    static fn (SearchScope $scope): string => $scope->alias,
                    $this->scopes,
                ))),
            ]);

            return [
                'results' => [],
                'diagnostics' => [
                    'status' => 'invalid_scope_selection',
                    'message' => 'No valid scope aliases selected for this search.',
                ],
            ];
        }

        Log::channel('search')->info('[SearchTool] Executing parallel search', [
            'scopes'         => array_map(fn (SearchScope $s) => $s->type->value . ':' . $s->alias, $scopesToSearch),
            'include_images' => $this->includeImages,
            'deep'           => $this->deep->value,
            'persistent_key' => $persistentKey,
            'searches_count' => count($searches),
            'requested_scope_aliases' => $requestedScopeAliases,
            'effective_scopes_count' => count($scopesToSearch),
        ]);

        $allScopeResults = $this->runScopes($scopesToSearch, $persistentKey, $searches);

        return $this->mergeResults($allScopeResults);
    }

    /**
     * @param SearchScope[] $scopes
     * @param array<int, array{query: string, scopeAliases: string[]}> $searches
     */
    private function runScopes(array $scopes, string $persistentKey, array $searches): array
    {
        $includeImages  = $this->includeImages;
        $model          = $this->model;
        $deep           = $this->deep;
        $maxParallel    = $this->maxParallel;
        $historyEnabled = $this->historyEnabled;

        $results = [];
        $tasks = [];

        foreach ($scopes as $scopeIndex => $scope) {
            $scopeQueries = [];
            foreach ($searches as $originalIndex => $search) {
                $aliases = $search['scopeAliases'] ?? [];
                if (empty($aliases) || in_array($scope->alias, $aliases, true)) {
                    $scopeQueries[] = ['original_index' => $originalIndex, 'query' => $search['query']];
                }
            }

            if ($scopeQueries === []) {
                $results[$scopeIndex] = ['results' => []];
                continue;
            }

            $queryLines = collect($scopeQueries)
                ->map(function (array $s, int $i) {
                    $query = json_encode($s['query'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    return ($i + 1) . ". query={$query}";
                })
                ->join("\n");

            $scopeKey = $persistentKey . ':' . $scope->type->value . ':' . $scope->alias;

            $tasks[$scopeIndex] = function () use ($scope, $scopeKey, $queryLines, $scopeQueries, $includeImages, $model, $deep, $historyEnabled): array {
                $result = (new SearchAgent(
                    $scopeKey,
                    $scope,
                    $includeImages,
                    $model,
                    $deep,
                    false,
                    null,
                    $historyEnabled,
                ))->respond("Search queries:\n{$queryLines}");

                if (! is_array($result) || ! isset($result['results'])) {
                    return ['results' => []];
                }

                $remapped = [];
                foreach ($result['results'] as $agentIndex => $queryResult) {
                    $originalIndex = $scopeQueries[$agentIndex]['original_index'] ?? $agentIndex;
                    $remapped[$originalIndex] = $queryResult;
                }

                return ['results' => $remapped];
            };
        }

        if ($tasks === []) {
            return $results;
        }

        $timeoutSeconds = (int) config('rag-chunks.search_tool_process_timeout', 300);

        $results = [];
        foreach (array_chunk($tasks, max(1, $maxParallel), true) as $taskChunk) {
            try {
                $chunkResults = $this->runTasksInProcessPool($taskChunk, $timeoutSeconds);
                foreach ($chunkResults as $taskIndex => $taskResult) {
                    $results[(int) $taskIndex] = $taskResult;
                }
            } catch (\Throwable $e) {
                Log::channel('search')->warning('[SearchTool] Parallel chunk execution failed, fallback to sequential', [
                    'error' => $e->getMessage(),
                    'tasks' => count($taskChunk),
                    'timeout_s' => $timeoutSeconds,
                ]);

                foreach ($taskChunk as $idx => $task) {
                    try {
                        $results[(int) $idx] = $task();
                    } catch (\Throwable $inner) {
                        Log::channel('search')->warning('[SearchTool] Scope task failed', [
                            'task_index' => $idx,
                            'error' => $inner->getMessage(),
                        ]);
                    }
                }
            }
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * @param array<int, callable(): array<string, mixed>> $tasks
     * @return array<int, array<string, mixed>>
     */
    private function runTasksInProcessPool(array $tasks, int $timeoutSeconds): array
    {
        /** @var ProcessFactory $processFactory */
        $processFactory = app(ProcessFactory::class);
        $command = Application::formatCommandString('invoke-serialized-closure');

        $results = $processFactory->pool(function (Pool $pool) use ($tasks, $command, $timeoutSeconds): void {
            foreach (Arr::wrap($tasks) as $key => $task) {
                $pool->as((string) $key)
                    ->command($command)
                    ->path(base_path())
                    ->timeout($timeoutSeconds)
                    ->env([
                        'LARAVEL_INVOKABLE_CLOSURE' => base64_encode(serialize(new SerializableClosure($task))),
                    ]);
            }
        })->start()->wait();

        return $results->collect()->mapWithKeys(function ($result, $key) use ($timeoutSeconds): array {
            if ($result->failed()) {
                Log::channel('search')->warning('[SearchTool] Process failed in pool', [
                    'task_index' => (int) $key,
                    'exit_code' => $result->exitCode(),
                    'error_output' => $result->errorOutput(),
                    'timeout_s' => $timeoutSeconds,
                ]);

                return [(int) $key => ['results' => []]];
            }

            $output = $result->output();

            if (($pos = strpos($output, "\x1f\x8b")) !== false) {
                $output = substr($output, 0, $pos);
            }

            $decoded = json_decode($output, true);

            if (! is_array($decoded) || ! ($decoded['successful'] ?? false)) {
                $message = is_array($decoded) ? ($decoded['message'] ?? 'Unknown worker error') : 'Invalid worker payload';
                Log::channel('search')->warning('[SearchTool] Worker error in pool', [
                    'task_index' => (int) $key,
                    'message' => (string) $message,
                    'timeout_s' => $timeoutSeconds,
                ]);

                return [(int) $key => ['results' => []]];
            }

            $unserialized = unserialize((string) $decoded['result']);
            if (! is_array($unserialized)) {
                Log::channel('search')->warning('[SearchTool] Worker returned non-array payload', [
                    'task_index' => (int) $key,
                ]);

                return [(int) $key => ['results' => []]];
            }

            return [(int) $key => $unserialized];
        })->all();
    }

    /**
     * Merges per-scope results. For each query index, unions chunk_ids,
     * merges relevant_chunks maps, and unions relevant_images.
     */
    private function mergeResults(array $allScopeResults): array
    {
        $mergedByQuery = [];

        foreach ($allScopeResults as $scopeResult) {
            $results = is_array($scopeResult) ? ($scopeResult['results'] ?? []) : [];

            foreach ($results as $queryIndex => $queryResult) {
                if (! isset($mergedByQuery[$queryIndex])) {
                    $mergedByQuery[$queryIndex] = [
                        'chunk_ids'       => [],
                        'relevant_chunks' => [],
                        'relevant_images' => [],
                    ];
                }

                $existing = &$mergedByQuery[$queryIndex];

                foreach ($queryResult['chunk_ids'] ?? [] as $id) {
                    if (! in_array($id, $existing['chunk_ids'], true)) {
                        $existing['chunk_ids'][] = $id;
                    }
                }

                foreach ($queryResult['relevant_chunks'] ?? [] as $id => $chunk) {
                    if (! isset($existing['relevant_chunks'][$id])) {
                        $existing['relevant_chunks'][$id] = $chunk;
                    }
                }

                $seenImageUrls = array_column($existing['relevant_images'], 'url');
                foreach ($queryResult['relevant_images'] ?? [] as $image) {
                    $url = $image['url'] ?? null;
                    if ($url !== null && ! in_array($url, $seenImageUrls, true)) {
                        $existing['relevant_images'][] = $image;
                        $seenImageUrls[]               = $url;
                    }
                }
            }
        }

        $mergedResults = array_values($mergedByQuery);

        Log::channel('search')->info('[SearchTool] Relevant chunks summary', [
            'query_count' => count($mergedResults),
            'query_chunk_counts' => array_values(array_map(
                static fn (array $result): int => count($result['chunk_ids'] ?? []),
                $mergedResults,
            )),
            'query_chunk_ids_sample' => array_values(array_map(
                static fn (array $result): array => array_slice($result['chunk_ids'] ?? [], 0, 10),
                $mergedResults,
            )),
        ]);

        return ['results' => $mergedResults];
    }
}
