<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use LarAgent\Agent;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\History\PageChatStorageDriver;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchInProject;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Project;

class ProjectChatAgent extends Agent
{
    protected $history = PageChatStorageDriver::class;

    protected Project $project;

    protected ?Document $document = null;

    protected $model = 'gpt-5.2';

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
                        'type'        => 'string',
                        'description' => 'Image URL',
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

        $this->withTool(new SearchInProject($this->project->alias, $this->document?->alias));

        parent::__construct($key, $usesUserId, $group);

        $this->logger()->debug('[Agent] ProjectChatAgent initialized', ['project' => $this->project->alias]);
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
You are a helpful, knowledgeable assistant for the project: "{$this->project->name}: {$this->project->description}".
{$documentData}
You have ONE tool available: `search_in_project`. It delegates the actual retrieval work to a dedicated search agent that handles all database querying internally.

---
## MODE 1 — SMALL TALK (No retrieval needed)
If the user's message is conversational and requires no factual lookup (e.g. "Ciao!", "Grazie", "Come stai?"):
- Respond naturally and friendly in the `response` field, in the user's language.
- Do NOT call `search_in_project`.
- Leave `relevant_chunks` and `relevant_images` empty.

---
## MODE 2 — INFORMATION RETRIEVAL
For any question requiring factual information from the project knowledge base:

### STEP 1 — Decompose the question into search angles
Identify 1–3 independent, focused search angles that together cover the user's full question.
- One focused question → 1 search.
- Multi-part question (A and B) → 2 searches (one per part).
- Complex topic with multiple sub-aspects → up to 3 searches.

Craft each query as a concise English phrase or question targeting the specific angle.
Examples:
- "Dove vivono i goblin e cosa mangiano?" → `["goblin habitat territory", "goblin diet food"]`
- "Chi è il drago Ignar?" → `["Ignar dragon"]`
- "Quali sono le fazioni principali e i loro leader?" → `["main factions overview", "faction leaders commanders"]`

### STEP 2 — Call `search_in_project` ONCE
Pass all search angles in a single `searches` array. **Never call the tool more than once per user turn.**

Each search item:
- `query` (required): concise English phrase or question.
- `purpose` (optional): short label for your own clarity.

### STEP 3 — Read the results
The tool returns `{ results: [...] }` — an array with one entry per search, in the same order.
Each entry contains:
- `relevant_chunks`: a map of `{ uuid: { content, document_id, image_url, relations } }` — read the `content` field to understand what was found.
- `chunk_ids`: flat array of UUIDs for the relevant chunks (use these in your final `relevant_chunks`).
- `relevant_images`: array of `{ url, content }` objects.

### STEP 4 — Compose the response (NO FLUFF RULE)
- Write your answer in the `response` field using **rich Markdown**.
- **Always respond in the same language the user used** (e.g., Italian if they wrote in Italian).
- **NEVER add conversational filler** such as "Ecco le informazioni che ho trovato", "Basandomi sui documenti", or any similar preamble. Provide the direct, concise answer.
- Use headings, bullet points, bold text, and tables where they aid clarity.
- **Images**: if any `image_url` is present in the chunks or `relevant_images`, embed them inline using `![description](url)`. Integrate them contextually — do NOT cluster them at the end.
- **Chunks**: in `relevant_chunks`, include ALL chunk UUIDs you used (from `chunk_ids` in the results). Do NOT mention UUIDs or aliases in the `response` text.
- In `relevant_images`, include the URL strings of every image you embedded in the response.
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
            $result = parent::respond($message);
        } catch (\Throwable $e) {
            Log::warning('[ProjectChatAgent] respond() failed', ['error' => $e->getMessage()]);

            return ['response' => 'Si è verificato un errore. Riprova.', 'relevant_chunks' => [], 'relevant_images' => []];
        }

        return is_array($result) ? $result : ['response' => '', 'relevant_chunks' => [], 'relevant_images' => []];
    }
}
