<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\ChunkMapper;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\GetChunksByAliases;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchChunks;
use SimoneBianco\LaravelRagChunks\Enums\RelationType;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Project;
use Throwable;

class ProjectSearchAgent extends RotableAgent
{
    protected $history = CacheStorage::class;

    protected Project $project;

    protected ?Document $document = null;

    protected $model = 'gpt-4.1-mini';

    protected $maxCompletionTokens = 16384;

    protected $parallelToolCalls = true;

    /**
     * Response schema: an array of result sets, one per input search query.
     * Each entry mirrors the per-query output of the agent.
     */
    protected $responseSchema = [
        'name'   => 'search_results',
        'schema' => [
            'type'       => 'object',
            'properties' => [
                'results' => [
                    'type'        => 'array',
                    'description' => 'One result entry per input search query, in the same order as the input list.',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'relevant_chunks' => [
                                'type'        => 'array',
                                'description' => 'Array of chunk IDs (UUIDs) that are relevant to this specific search query. Include every single relevant chunk — do not truncate.',
                                'items'       => [
                                    'type'        => 'string',
                                    'description' => 'Chunk ID (UUID), taken verbatim from the search results keys.',
                                ],
                            ],
                            'relevant_images' => [
                                'type'  => 'array',
                                'description' => 'Images found in the chunks relevant to this query.',
                                'items' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'url'     => ['type' => 'string', 'description' => 'Image URL'],
                                        'content' => ['type' => 'string', 'description' => 'Brief description of what the image shows'],
                                    ],
                                    'required'             => ['url', 'content'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required'             => ['relevant_chunks', 'relevant_images'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required'             => ['results'],
            'additionalProperties' => false,
        ],
        'strict' => true,
    ];

    protected function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    public function __construct(
        $key,
        string $projectAlias,
        ?string $documentAlias = null,
        bool $usesUserId = false,
        ?string $group = null
    ) {
        $this->project = Project::query()
            ->when($documentAlias, function (Builder $query) use ($documentAlias) {
                $query->with(['documents' => function (Builder $query) use ($documentAlias) {
                    $query->where('alias', $documentAlias);
                }]);
            })
            ->where('alias', $projectAlias)
            ->firstOrFail();

        $this->document = $this->project['documents']?->where('alias', $documentAlias)->first();

        $this->withTool(new SearchChunks($this->project, $this->document));
        $this->withTool(new GetChunksByAliases($this->project, $this->document));

        parent::__construct($key, $usesUserId, $group);

        $this->logger()->debug('[Agent] ProjectSearchAgent initialized', [
            'project' => $this->project->alias,
            'document' => $this->document?->alias,
            'search_key' => (string) $key,
        ]);
    }

    public function instructions(): string
    {
        $projectInstructions = $this->project->settings?->search_agent_instructions;
        $projectInstructionsBlock = ! empty($projectInstructions)
            ? "\nPROJECT-SPECIFIC INSTRUCTIONS\n{$projectInstructions}\n"
            : '';

        $documentDescription = '';
        if ($this->document) {
            $documentDescription = "Search is limited to document {$this->document->name}: {$this->document->description}";
        }

        return <<<INSTRUCTIONS
You are a restricted retrieval agent for project "{$this->project->name}: {$this->project->description}".
$documentDescription
Return structured data only (UUIDs + images). No prose.

OBJECTIVE
- Execute retrieval with tools and output `{ results: [...] }`.
- Each result item must include `relevant_chunks` and `relevant_images`.
- Include ALL relevant chunk UUIDs; do not truncate.

DEFAULT TOOLING
- First attempt: use `search_chunks`.
- Use `get_chunks_by_aliases` only when exact chunk UUIDs are already known.

SEARCH INPUT POLICY
- Always provide: `textSearch`, `semanticTagsSearch`, `questionsSearch`.
- `hasImage`: use `mixed` by default; `with` only for explicit visual requests; `without` only for explicit text-only requests.
- `documentsAliases` only when document focus is needed.

REFINEMENT-ONLY FIELDS
- `keywordsSearch`, `chapters`, and `tag_*` are NEVER first-attempt fields.
- Use them from attempt 2 only.
- `keywordsSearch`: prefer `OR` first, `AND` only for stricter disambiguation; substring matching is allowed.
- `chapters`: use only chapter aliases discovered in prior results.
- `tag_*`: never guess values; use exact enum values only; prefer one filter unless strict intersection is required.

RETRY POLICY
- Max 2 attempts.
- Always set `allowRelaxTagFilters=true` on `search_chunks`.
- If first attempt has low recall, broaden/rewrite query fields and optionally add refinement-only fields.
- For page=1/perPage=10, if first pass returns < 7 chunks, MUST run a second `search_chunks` call.
- If `hasImage=mixed` and results are weak, you may retry with `without` unless user explicitly requested visuals.

RESULT EXTRACTION
- Collect UUIDs from relevant chunks across all returned documents.
- Include chunk images in `relevant_images` when present.

LANGUAGE
- Default retrieval language is English, but adapt lexical choices to chunk/document language when useful.
$projectInstructionsBlock
INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }

    public function respond(?string $message = null): string|array|DataModel|MessageInterface
    {
        try {
            $this->injectInstructionsForCurrentTurn();
            $decoded = parent::respond($message);
        } catch (Throwable $e) {
            Log::warning('[ProjectSearchAgent] respond() failed', ['error' => $e->getMessage()]);

            return ['results' => []];
        }

        if (! is_array($decoded) || empty($decoded['results'])) {
            $this->logger()->info('[ProjectSearchAgent] No results returned', [
                'project' => $this->project->alias,
                'document' => $this->document?->alias,
            ]);

            return ['results' => []];
        }

        $this->logger()->info('[ProjectSearchAgent] Search completed', [
            'project' => $this->project->alias,
            'document' => $this->document?->alias,
            'results_count' => count($decoded['results']),
        ]);

        return ['results' => $this->resolveResults($decoded['results'])];
    }

    private function resolveResults(array $results): array
    {
        return array_map(function (array $searchResult) {
            $chunkIds = $searchResult['relevant_chunks'] ?? [];

            if (empty($chunkIds)) {
                return [
                    'chunk_ids'       => [],
                    'relevant_chunks' => [],
                    'relevant_images' => $searchResult['relevant_images'] ?? [],
                ];
            }

            $chunks = Chunk::query()
                ->whereIn('id', $chunkIds)
                ->with([
                    'dedupMedia',
                    'outgoingRelations.to_entity',
                    'incomingRelations' => function ($q) {
                        $q->where('type', RelationType::BIDIRECTIONAL->value)->with('from_entity');
                    },
                ])
                ->withNeighborSnippets()
                ->get();

            return [
                'chunk_ids'       => $chunks->pluck('id')->values()->toArray(),
                'relevant_chunks' => $chunks->mapWithKeys(fn (Chunk $chunk) => [
                    $chunk->id => ChunkMapper::loadAndMap($chunk),
                ])->toArray(),
                'relevant_images' => $searchResult['relevant_images'] ?? [],
            ];
        }, $results);
    }
}
