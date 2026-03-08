<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use Illuminate\Support\Str;
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
            'searches' => [
                'type'        => 'array',
                'description' => 'Array of independent search queries to execute in parallel. Each item targets a different aspect or angle of the user\'s question. Include 1-3 searches per call — never call this tool multiple times in a single turn.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => [
                            'type'        => 'string',
                            'description' => 'The search query in ENGLISH, focused on the core concept (e.g. "goblin tribe rituals", "founding of the empire", "what do goblins eat?"). Be concise and specific.',
                        ],
                        'hasImage' => [
                            'type'        => 'boolean',
                            'description' => 'If true, force image-only retrieval for this search angle (chunks with images only). Use when user explicitly asks to show/see maps/images/diagrams.',
                        ],
                        'purpose' => [
                            'type'        => 'string',
                            'description' => 'Brief label for this search angle, for your own bookkeeping (e.g. "habitat", "diet", "history"). Not used by the search engine.',
                        ],
                    ],
                    'required'             => ['query'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    protected array $required = ['searches'];

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    protected function handle(array|DataModel $input): mixed
    {
        $searches = is_array($input) ? ($input['searches'] ?? []) : $input->toArray()['searches'] ?? [];

        $normalizedSearches = collect($searches)
            ->filter(fn ($s) => is_array($s))
            ->map(function (array $s) {
                $query = trim((string) ($s['query'] ?? ''));

                if ($query === '') {
                    return null;
                }

                return [
                    'query' => str_replace(["\r\n", "\n", "\r"], ' ', $query),
                    'hasImage' => array_key_exists('hasImage', $s) ? (bool) $s['hasImage'] : null,
                ];
            })
            ->filter()
            ->values();

        $count = $normalizedSearches->count();

        if ($count === 0) {
            return ['results' => []];
        }

        $queryLines = $normalizedSearches
            ->map(function (array $s, int $i) {
                $hasImage = $s['hasImage'] === null
                    ? 'null'
                    : ($s['hasImage'] ? 'true' : 'false');

                $query = json_encode($s['query'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return ($i + 1) . ". query={$query}; hasImage={$hasImage}";
            })
            ->join("\n");

        $message = "Execute the following {$count} search(es) in parallel using search_chunks. "
            . "Return a `results` array with exactly {$count} entr" . ($count === 1 ? 'y' : 'ies') . ", "
            . "one per search, in the same order.\n"
            . "CRITICAL: if a line has hasImage=true, you MUST pass hasImage=true to search_chunks for that query. "
            . "If hasImage=null, decide based on query intent.\n\n"
            . $queryLines;

        $result = new ProjectSearchAgent(
            Str::random(),
            $this->projectAlias,
            $this->documentAlias
        )->respond($message);

        return is_array($result) ? $result : $result->getContent();
    }
}
