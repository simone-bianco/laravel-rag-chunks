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
    protected $history = 'in_memory';

    protected Project $project;

    protected ?Document $document = null;

    protected $model = 'gpt-4.1-mini';

    protected $maxCompletionTokens = 16384;

    // Must be false: with true the LLM can emit search+navigate calls in the same turn,
    // creating tool_call_ids that the loop cannot satisfy in order → corrupt history.
    protected $parallelToolCalls = false;

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
                                'description' => 'img url',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'brief explanation of content',
                            ],
                        ],
                        'required' => ['url', 'content'],
                        'additional_properties' => false,
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
- `keywordsSearch`: STRICTLY FORBIDDEN on the first attempt. NEVER use it unless you got 0 results on the first try and need an exact word match fallback.
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

**STEP 5: FINAL OUTPUT (CRITICAL: DO NOT TRUNCATE RESULTS)**
- Output the raw, direct answer based ONLY on the retrieved text.
- ZERO conversational filler. No introductions.
- YOU MUST EXTRACT AND RETURN **EVERY SINGLE** RELEVANT CHUNK ID you found in the search results. DO NOT stop at 1 or 2 chunks if more are relevant. DO NOT summarize or be lazy. If the tool returns 4 relevant chunks, your `relevant_chunks` array MUST contain exactly 4 IDs.
$projectInstructionsBlock
INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }

    public function respond(?string $message = null): string|array|DataModel|MessageInterface
    {
        // Force raw MessageInterface return to bypass LarAgent's structured-output pipeline.
        // The pipeline crashes with "processBeforeStructuredOutput(): Argument #1 must be array, null given"
        // when the LLM returns null content after a tool-call loop. By getting the raw message,
        // we can json_decode manually and handle null gracefully.
        $this->returnMessage = true;

        try {
            $raw = parent::respond($message);
        } catch (\Throwable $e) {
            Log::warning('[ProjectSearchAgent] respond() failed, returning empty result', [
                'error' => $e->getMessage(),
            ]);

            return [
                'chunk_ids' => [],
                'relevant_chunks' => [],
                'relevant_images' => [],
                'proposed_connections' => [],
            ];
        }

        // Extract content from the raw MessageInterface.
        // Important: getContent() returns a MessageContent object that implements __toString(),
        // so we MUST cast it to (string) to pass it to json_decode().
        //
        // NOTE FOR DEBUGGING: adding explicit variable types in logs to ensure we see exactly
        // what class is returned if it still fails.
        Log::debug('[ProjectSearchAgent] Inspecting LLM raw output', [
            'raw_type' => gettype($raw),
            'raw_class' => is_object($raw) ? get_class($raw) : null,
            'raw_content' => $raw instanceof MessageInterface ? (string) $raw->getContent() : (is_string($raw) ? $raw : 'null'),
        ]);

        $content = $raw instanceof MessageInterface ? (string) $raw->getContent() : (is_string($raw) ? $raw : null);

        // Sanitize the content before decoding
        $sanitizedContent = $content;
        if (is_string($sanitizedContent)) {
            // Remove markdown codeblock wrapping if strictly present
            $sanitizedContent = preg_replace('/^```(?:json)?\s*(.*?)\s*```$/s', '$1', trim($sanitizedContent));

            // If the model output the JSON twice (e.g. {...}\n{...}), extract only the first complete object
            // Improved regex to handle newlines between objects better
            if (preg_match('/^(\{\s*".*?\})\s*\{/s', $sanitizedContent, $matches)) {
                $sanitizedContent = $matches[1];
                Log::debug('[ProjectSearchAgent] Extracted first JSON object from duplicated output');
            }

            // Remove trailing commas in arrays/objects
            $sanitizedContent = preg_replace('/,\s*([\]}])/m', '$1', $sanitizedContent);
        }

        Log::debug('[ProjectSearchAgent] Content after sanitization', [
            'sanitized_content' => $sanitizedContent,
        ]);

        $decoded = is_string($sanitizedContent) ? json_decode($sanitizedContent, true) : (is_array($raw) ? $raw : null);

        if (! is_array($decoded)) {
            Log::warning('[ProjectSearchAgent] LLM returned non-JSON content', [
                'raw_class' => is_object($raw) ? get_class($raw) : null,
                'content_type' => gettype($content),
                'content_preview' => is_string($content) ? mb_substr($content, 0, 1000) : null,
                'json_error' => json_last_error_msg(),
                'sanitized_preview' => is_string($sanitizedContent) ? mb_substr($sanitizedContent, 0, 1000) : null,
            ]);

            return [
                'chunk_ids' => [],
                'relevant_chunks' => [],
                'relevant_images' => [],
                'proposed_connections' => [],
            ];
        }

        if (empty($decoded['relevant_chunks'])) {
            return [
                'chunk_ids' => [],
                'relevant_chunks' => $decoded['relevant_chunks'] ?? [],
                'relevant_images' => $decoded['relevant_images'] ?? [],
                'proposed_connections' => $decoded['proposed_connections'] ?? [],
            ];
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
