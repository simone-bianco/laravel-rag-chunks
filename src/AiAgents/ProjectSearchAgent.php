<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use LarAgent\Agent;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\ChunkMapper;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\ConnectChunks;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\GetNextChunk;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\GetPreviousChunk;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchChunks;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Project;

class ProjectSearchAgent extends Agent
{
    protected $history = 'database';

    protected Project $project;

    protected ?Document $document = null;

    protected $model = 'gpt-5-mini';

    protected $parallelToolCalls = true;

    protected $responseSchema = [
        'name' => 'search_result',
        'schema' => [
            'type' => 'object',
            'properties' => [
                'relevant_chunks' => [
                    'type' => 'array',
                    'description' => 'Array of relevant chunks aliases',
                    'items' => [
                        'type' => 'string',
                        'description' => 'chunk alias',
                    ],
                ],
                'relevant_images' => [
                    'type' => 'array',
                    'description' => 'Array of relevant images',
                    'items' => [
                        'type' => 'object',
                        'description' => 'single image',
                        'properties' => [
                            'url' => [
                                'type' => 'string',
                                'description' => 'img url'
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'brief explanation of content'
                            ]
                        ],
                        'required' => ['url', 'content'],
                        'additional_properties' => false
                    ],
                ],
                'proposed_connections' => [
                    'type' => 'array',
                    'description' => 'A list of proposed connections between chunks and documents',
                    'items' => [
                        'type' => 'object',
                        'description' => 'Single connection object',
                        'properties' => [
                            'source_entity_type' => [
                                'type' => 'string',
                                'description' => 'type of source entity',
                                'enum' => ['chunk', 'document'],
                            ],
                            'source_entity_alias' => [
                                'type' => 'string',
                                'description' => 'alias of source entity',
                            ],
                            'destination_entity_type' => [
                                'type' => 'string',
                                'description' => 'type of destination entity',
                                'enum' => ['chunk', 'document'],
                            ],
                            'destination_entity_alias' => [
                                'type' => 'string',
                                'description' => 'alias of destination entity',
                            ],
                            'relation_type' => [
                                'type' => 'string',
                                'description' => 'unidirectional if goes only from A to B, bidirectional if can be read in both ways',
                                'enum' => ['bidirectional', 'unidirectional'],
                            ],
                        ],
                        'required' => [
                            'source_entity_type',
                            'source_entity_alias',
                            'destination_entity_type',
                            'destination_entity_alias',
                            'relation_type',
                        ],
                        'additional_properties' => false,
                    ],
                ],
            ],
            'required' => ['relevant_chunks', 'proposed_connections'],
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
        $this->withTool(new GetPreviousChunk);
        $this->withTool(new GetNextChunk);
        $this->withTool(new ConnectChunks);

        parent::__construct($key, $usesUserId, $group);

        $this->logger()->debug('[Agent] ProjectSearchAgent initialized', ['project' => $this->project->alias]);
    }

    public function instructions(): string
    {
        $projectInstructions = $this->project->settings?->search_agent_instructions;
        $projectInstructionsBlock = ! empty($projectInstructions)
            ? "\n### PROJECT-SPECIFIC INSTRUCTIONS\n{$projectInstructions}\n"
            : '';

        return <<<INSTRUCTIONS
You are a highly restricted, literal-minded RAG Retrieval Agent. Do not overthink. Do not deduce. Follow this EXACT algorithm step-by-step.

You are working on: "{$this->project->name}: {$this->project->description}".
**CRITICAL: LANGUAGE RULE**
The user speaks Italian, but the database is in ENGLISH.
1. Translate the user's core concepts to ENGLISH before searching.
2. Reply in the EXACT language of the retrieved text (ENGLISH).

**STEP 1: HOW TO CALL search_chunks**
- If the user asks a multi-part question (e.g., "A and B"), DO NOT search for both at once. Search for the most specific entity first.
- `textSearch`: USE MAXIMUM 3 OR 4 ENGLISH WORDS. Strip all verbs and grammar. NEVER use quotes (""). (Example: dried dung beetles barrel)
- `semanticTagsSearch`: 2 or 3 comma-separated English words.
- `tag_*` parameters: LEAVE THEM NULL. NEVER GUESS a location or category. ONLY use them if the user EXPLICITLY types the exact name of a place.

**STEP 2: HOW TO HANDLE RESULTS (THE ANTI-LOOP RULE)**
- Read the retrieved chunks. Mentally translate the user's Italian query to see if the English text matches (e.g. "scarabei" = "beetles").
- IF YOU FIND A PARTIAL ANSWER (e.g., you find the tea, but not the barrel): YOU ARE STRICTLY FORBIDDEN FROM CALLING `search_chunks` AGAIN. You MUST immediately call `get_next_chunk` and `get_previous_chunk` on the ID of the chunk that had the partial answer. The missing context is always there.
- IF YOU FIND NOTHING: Retry `search_chunks` exactly ONCE with fewer, broader keywords and absolutely NO tags.

**STEP 3: CIRCUIT BREAKER**
- MAXIMUM 3 SEARCH ATTEMPTS TOTAL.
- If you hit 3 attempts and still have nothing, STOP. Output exactly: "Information not found in the database." No apologies.

**STEP 4: PROPOSING CONNECTIONS (USE EXTREMELY SPARINGLY)**
- In your `proposed_connections` array, ONLY return CRITICAL connections that significantly facilitate future retrieval by reducing search steps.
- Use this strictly for highly cross-referenced data where knowing entity A means the system will almost certainly need entity B immediately.
- DO NOT return obvious, trivial, or purely sequential connections. Less is more.

**STEP 5: FINAL OUTPUT**
- Output the raw, direct answer based ONLY on the retrieved text.
- ZERO conversational filler. No introductions.
$projectInstructionsBlock
INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }

    public function respond(?string $message = null): string|array|DataModel|MessageInterface
    {
        $raw = parent::respond($message);

        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);

        if (! is_array($decoded) || empty($decoded['relevant_chunks'])) {
            return $decoded ?? $raw;
        }

        $aliases = $decoded['relevant_chunks'];

        $chunks = Chunk::query()
            ->whereIn('id', $aliases)
            ->withNeighborSnippets()
            ->get();

        $decoded['chunk_ids'] = $chunks->pluck('id')->values()->toArray();

        $decoded['relevant_chunks'] = $chunks
            ->mapWithKeys(fn (Chunk $chunk) => [
                $chunk->id => ChunkMapper::loadAndMap($chunk),
            ])
            ->toArray();

        return $decoded;
    }
}
