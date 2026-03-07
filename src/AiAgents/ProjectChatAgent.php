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
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\GetAdjacentChunk;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchChunks;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Project;

class ProjectChatAgent extends Agent
{
    protected $history = null;

    protected Project $project;

    protected ?Document $document = null;

    protected $model = 'gpt-4.1-mini'; // Mantenuto dal Chat Agent originale

    protected $maxCompletionTokens = 16384;

    // Deve essere false per evitare che l'LLM chiami search e navigate nello stesso turno,
    // corrompendo la cronologia delle chiamate ai tool.
    protected $parallelToolCalls = false;

    protected $responseSchema = [
        'name' => 'agent_response',
        'schema' => [
            'type' => 'object',
            'properties' => [
                'response' => [
                    'type' => 'string',
                    'description' => 'The assistant\'s final response to the user in rich Markdown. Can be conversational or informational.',
                ],
                'relevant_chunks' => [
                    'type' => 'array',
                    'description' => 'Array of relevant chunks IDs used to build the response. Leave empty for small talk.',
                    'items' => [
                        'type' => 'string',
                        'description' => 'chunk ID (UUID)',
                    ],
                ],
                'relevant_images' => [
                    'type' => 'array',
                    'description' => 'Array of relevant images. Leave empty for small talk.',
                    'items' => [
                        'type' => 'object',
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
                    'description' => 'Proposed connections between chunks/documents. Leave empty for small talk.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'source_entity_type' => [
                                'type' => 'string',
                                'enum' => ['chunk', 'document'],
                            ],
                            'source_entity_alias' => [
                                'type' => 'string',
                            ],
                            'destination_entity_type' => [
                                'type' => 'string',
                                'enum' => ['chunk', 'document'],
                            ],
                            'destination_entity_alias' => [
                                'type' => 'string',
                            ],
                            'relation_type' => [
                                'type' => 'string',
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
            'required' => ['response', 'relevant_chunks', 'relevant_images', 'proposed_connections'],
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

        // Integriamo tutti i tool del Search Agent
        $this->withTool(new SearchChunks($this->project, $this->document));
        $this->withTool(new GetAdjacentChunk);
        $this->withTool(new ConnectChunks);

        parent::__construct($key, $usesUserId, $group);

        $this->logger()->debug('[Agent] ProjectChatAgent initialized (Unified)', ['project' => $this->project->alias]);
    }

    public function instructions(): string
    {
        $projectInstructions = $this->project->settings?->search_agent_instructions;
        $projectInstructionsBlock = ! empty($projectInstructions)
            ? "\n### PROJECT-SPECIFIC INSTRUCTIONS\n{$projectInstructions}\n"
            : '';

        $documentData = ! empty($this->document)
            ? "You are scoped to document: \"{$this->document->name}\".\n"
            : '';

        return <<<INSTRUCTIONS
You are an advanced, helpful assistant for the project: "{$this->project->name}: {$this->project->description}".
{$documentData}

You have two main modes of operation: Conversational and Retrieval (RAG).

---
## 1. CONVERSATIONAL MODE (Small Talk)
If the user's message does NOT require looking up information (e.g., "Ciao!", "Grazie", "Come stai?"), respond friendly in the `response` property in the user's language.
- DO NOT call any search tools.
- Leave `relevant_chunks`, `relevant_images`, and `proposed_connections` arrays EMPTY.

---
## 2. RETRIEVAL MODE (Information Search)
If the user asks for information, follow this EXACT algorithm:

**CRITICAL: LANGUAGE RULE**
The user speaks Italian, but the database is in ENGLISH. Translate the user's core concepts to ENGLISH before searching.

**STEP 1: HOW TO CALL search_chunks**
- `textSearch`: USE MAXIMUM 3 OR 4 ENGLISH WORDS. Strip all verbs and grammar.
- `semanticTagsSearch`: 2 or 3 comma-separated English words.
- `keywordsSearch`: STRICTLY FORBIDDEN on the first attempt.
- `tag_*` parameters: LEAVE THEM NULL unless the user explicitly names a place.

**STEP 2: HANDLING RESULTS (THE INVESTIGATION LOOP)**
- NEVER GUESS or output "there are others not named". You have the tools to find them!
- If a chunk abruptly cuts off, or genericizes names (like "the other wyrmlords"), YOU MUST call `get_adjacent_chunk` on THAT chunk's ID specifying `direction` (previous or next) to read the missing text.
- If you find NOTHING relevant OR you need a highly specific subset (e.g. searching exact Wyrmlord names): Retry `search_chunks` exactly ONCE prioritizing `keywordsSearch` or extremely specific `tag_*` parameters to bypass pagination limits.
- **ABSOLUTE MAXIMUM: 3 SEARCH/ADJACENT ATTEMPTS.** If you hit 3 successive tool calls and still lack the full info, STOP and output what you have. DO NOT loop endlessly through pages.

**STEP 3: FORMATTING THE FINAL RESPONSE (NO FLUFF RULE)**
- Write your answer in the `response` property using **rich Markdown** in the **same language the user used** (e.g., Italian).
- **CRITICAL: NEVER ADD CONVERSATIONAL FLUFF.** Do not say "Ecco le informazioni che ho trovato", "Basandomi sui documenti", or summarize irrelevant details. Give the raw, direct, and concise facts the user asked for.
- Use headings, bullet points, and bold text where appropriate.
- **IMAGES**: Embed them inline contextually in the `response` using Markdown: `![description](url)`. Alternate text and images. DO NOT cluster them at the end.
- **CHUNKS**: You MUST extract and return EVERY SINGLE relevant chunk ID you found in the `relevant_chunks` array.
- DO NOT list chunk IDs, UUIDs, or aliases in the `response` text. Do not say things like "Other related chunks:". Just answer naturally.
- **CONNECTIONS**: In `proposed_connections`, only return CRITICAL, highly cross-referenced connections. Use sparingly.
$projectInstructionsBlock
INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }

    public function respond(string|\LarAgent\Core\Contracts\Message|null $message = null): array|DataModel|MessageInterface
    {
        // Bypassiamo la pipeline di output strutturato di LarAgent per gestire
        // manualmente il parsing e l'idratazione dei Chunk.
        $this->returnMessage = true;

        try {
            $raw = parent::respond($message);
        } catch (\Throwable $e) {
            Log::warning('[ProjectChatAgent] respond() failed, returning empty result', [
                'error' => $e->getMessage(),
            ]);

            return [
                'response' => 'Si è verificato un errore durante l\'elaborazione della richiesta.',
                'chunk_ids' => [],
                'relevant_chunks' => [],
                'relevant_images' => [],
                'proposed_connections' => [],
            ];
        }

        Log::debug('[ProjectChatAgent] Inspecting LLM raw output', [
            'raw_type' => gettype($raw),
            'raw_class' => is_object($raw) ? get_class($raw) : null,
            'raw_content' => $raw instanceof MessageInterface ? (string) $raw->getContent() : (is_string($raw) ? $raw : 'null'),
        ]);

        $content = $raw instanceof MessageInterface ? (string) $raw->getContent() : (is_string($raw) ? $raw : null);

        // Pulizia del contenuto prima del decode
        $sanitizedContent = $content;
        if (is_string($sanitizedContent)) {
            $sanitizedContent = preg_replace('/^```(?:json)?\s*(.*?)\s*```$/s', '$1', trim($sanitizedContent));

            if (preg_match('/^(\{\s*".*?\})\s*\{/s', $sanitizedContent, $matches)) {
                $sanitizedContent = $matches[1];
                Log::debug('[ProjectChatAgent] Extracted first JSON object from duplicated output');
            }

            $sanitizedContent = preg_replace('/,\s*([\]}])/m', '$1', $sanitizedContent);
        }

        $decoded = is_string($sanitizedContent) ? json_decode($sanitizedContent, true) : (is_array($raw) ? $raw : null);

        if (! is_array($decoded)) {
            Log::warning('[ProjectChatAgent] LLM returned non-JSON content', [
                'json_error' => json_last_error_msg(),
                'sanitized_preview' => is_string($sanitizedContent) ? mb_substr($sanitizedContent, 0, 1000) : null,
            ]);

            return [
                'response' => is_string($sanitizedContent) ? $sanitizedContent : 'Errore nella formattazione della risposta strutturata.',
                'chunk_ids' => [],
                'relevant_chunks' => [],
                'relevant_images' => [],
                'proposed_connections' => [],
            ];
        }

        // Se non ci sono chunk rilevanti (es. small talk o ricerca fallita),
        // restituiamo comunque la risposta testuale.
        if (empty($decoded['relevant_chunks'])) {
            return [
                'response' => $decoded['response'] ?? '',
                'chunk_ids' => [],
                'relevant_chunks' => [],
                'relevant_images' => $decoded['relevant_images'] ?? [],
                'proposed_connections' => $decoded['proposed_connections'] ?? [],
            ];
        }

        // Idratazione dei Chunk dal Database se la ricerca ha prodotto risultati
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

        // Ritorna l'oggetto decodificato completo: response, chunk_ids, relevant_chunks, images, connections
        return $decoded;
    }
}
