<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\Concerns\NormalizesChunkIds;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\AiAgents\History\PageChatStorageDriver;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchInProject;
use SimoneBianco\LaravelRagChunks\Models\Project;
use SimoneBianco\LaravelRagChunks\Models\Document;

class ProjectChatAgent extends RotableAgent
{
    use NormalizesChunkIds;

    protected $history = PageChatStorageDriver::class;

    protected Project $project;

    protected ?Document $document = null;

    protected $model = 'gpt-5.4';

    protected $maxCompletionTokens = 16384;

    protected $parallelToolCalls = false;

    protected $responseSchema = [
        'name'   => 'agent_response',
        'schema' => [
            'type'       => 'object',
            'properties' => [
                'response' => [
                    'type'        => 'string',
                    'description' => 'The assistant\'s final response to the user in rich Markdown. Can be conversational or informational. Always in the same language the user used.',
                ],
                'relevant_chunks' => [
                    'type'        => 'array',
                    'description' => 'Array of chunk IDs (UUIDs) that were used to build this response. Collect them from the `chunk_ids` fields in the search results. Leave empty for small talk.',
                    'items'       => [
                        'type'        => 'string',
                        'description' => 'Chunk ID (UUID)',
                    ],
                ],
                'relevant_images' => [
                    'type'        => 'array',
                    'description' => 'Array of image URLs referenced in the response. Leave empty for small talk.',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'url'     => [
                                'type'        => 'string',
                                'description' => 'Image URL',
                            ],
                            'content' => [
                                'type'        => 'string',
                                'description' => 'Brief description of what the image shows',
                            ],
                        ],
                        'required'             => ['url', 'content'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required'             => ['response', 'relevant_chunks', 'relevant_images'],
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

        parent::__construct($key, $usesUserId, $group);

        $this->withTool(new SearchInProject($this->project->alias, $this->document?->alias));

        $this->logger()->debug('[Agent] ProjectChatAgent initialized', [
            'project' => $this->project->alias,
            'document' => $this->document?->alias,
            'chat_key' => (string) $key,
        ]);
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
You assist users inside project "{$this->project->name}: {$this->project->description}".
{$documentData}
You have ONE tool: `search_in_project`.

## SCOPE (MANDATORY)
- Project content is the only factual source.
- For entities (characters, places, factions, events), assume project context by default.
- Do NOT bring external universes/editions unless explicitly requested.
- Do NOT use prior world knowledge for factual answers; ground on retrieved chunks only.

## MODE 1 — SMALL TALK
If the message is conversational and needs no lookup:
- Reply naturally in the user's language.
- Do NOT call `search_in_project`.
- Return empty `relevant_chunks` and `relevant_images`.

## MODE 2 — RETRIEVAL
For factual/project questions:

### 1) Deconstruct the request
- Break down the user's query into distinct informational needs (entities, topics, relationships, visual elements).
- Identify all relevant search angles: characters, places, factions, events, items, rules, lore, and any visual references (maps, diagrams, illustrations).

### 2) Build comprehensive search angles
- Create 1-5 focused search items in English covering ALL identified aspects.
- **CRITICAL**: if the user's request can span multiple areas, you MUST deconstruct it and use multiple search angles (one angle per area).
- Single specific question -> 1 item; broad/multi-topic queries -> 3-5 items covering each distinct area.
- Always include at least one visual angle (`map`, `image`, `diagram`, `layout`, `illustration`) when visual context would be helpful.

### 3) Multi-search tool call
- Call `search_in_project` exactly ONCE per user turn.
- Send ALL angles in a SINGLE `searches` array (combined multi-search).
- Each item in `searches` must be a plain string query.
- Always send `persistentKey`:
  - Reuse the same key to refine/continue the same research thread across turns.
  - Use a new random key only when the user starts a fresh search thread.

### 4) Use results
- Tool output is `{ results: [...] }`, one entry per search item.
- Use `chunk_ids` to populate final `relevant_chunks`.
- Use returned images plus chunk image URLs.

### 5) Compose final answer
- Write `response` in rich Markdown, same language as user.
- NEVER add preambles like "Ecco cosa ho trovato" or "Basandomi sui documenti".
- If data is missing, state it clearly and ask one concise in-scope follow-up.
- Embed images inline with `![description](url)`; never output raw URL lists.
- Include all used chunk UUIDs in `relevant_chunks`, but never print UUIDs in `response`.
- Include each embedded image in `relevant_images` as `{url, content}`.

---

$projectInstructionsBlock
INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }

    public function respond(string|MessageInterface|null $message = null): array|DataModel|MessageInterface
    {
        try {
            $this->injectInstructionsForCurrentTurn();
            $result = parent::respond($message);
        } catch (\Throwable $e) {
            Log::warning('[ProjectChatAgent] respond() failed', ['error' => $e->getMessage()]);

            return ['response' => 'Si è verificato un errore. Riprova.', 'relevant_chunks' => [], 'relevant_images' => []];
        }

        if (! is_array($result)) {
            return ['response' => '', 'relevant_chunks' => [], 'relevant_images' => []];
        }

        return $this->normalizeResult($result);
    }

    private function normalizeResult(array $result): array
    {
        $chunkIds = $this->normalizeChunkIds(is_array($result['relevant_chunks'] ?? null)
            ? $result['relevant_chunks']
            : []);

        return [
            'response' => (string) ($result['response'] ?? ''),
            'relevant_chunks' => $chunkIds,
            'relevant_images' => $this->normalizeRelevantImages($result['relevant_images'] ?? [], $chunkIds),
        ];
    }

    private function normalizeRelevantImages(array $images, array $chunkIds): array
    {
        $normalized = [];

        foreach ($images as $image) {
            if (is_string($image) && $image !== '') {
                $normalized[] = [
                    'url' => $image,
                    'content' => 'Immagine rilevante',
                ];

                continue;
            }

            if (! is_array($image)) {
                continue;
            }

            $url = isset($image['url']) && is_string($image['url']) ? trim($image['url']) : '';

            if ($url === '') {
                continue;
            }

            $content = isset($image['content']) && is_string($image['content'])
                ? trim($image['content'])
                : '';

            $normalized[] = [
                'url' => $url,
                'content' => $content !== '' ? $content : 'Immagine rilevante',
            ];
        }

        if (! empty($chunkIds)) {
            $chunks = Chunk::query()->whereIn('id', $chunkIds)->get();

            foreach ($chunks as $chunk) {
                $url = $chunk->getFirstMedia()?->getUrl();

                if (! is_string($url) || $url === '') {
                    continue;
                }

                $normalized[] = [
                    'url' => $url,
                    'content' => Str::limit(trim((string) $chunk->content), 120),
                ];
            }
        }

        return collect($normalized)
            ->unique('url')
            ->values()
            ->toArray();
    }
}
