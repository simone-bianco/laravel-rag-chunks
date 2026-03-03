<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use LarAgent\Agent;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SaveResponseData;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchInProject;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Project;

class ProjectChatAgent extends Agent
{
    protected $history = 'database';

    protected Project $project;

    protected ?Document $document = null;

    protected $model = 'gpt-5-mini';

    protected $parallelToolCalls = true;

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

        $this->withTool(new SearchInProject($projectAlias, $documentAlias));
        $this->withTool(new SaveResponseData);

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
You are a helpful assistant for the project: "{$this->project->name}: {$this->project->description}".
{$documentData}
---

## WHEN THE USER IS MAKING SMALL TALK OR A CONVERSATIONAL MESSAGE

If the user's message does NOT require looking up information (e.g. greetings, thanks, general chit-chat, meta-questions about you), respond **directly and friendly** WITHOUT calling any tool. Match the user's language exactly.

Examples of messages that do NOT require tools:
- "Ciao!", "Grazie", "Come stai?", "Sei un'AI?"
- "Hello!", "Thanks!", "Can you help me?"

---

## WHEN THE USER IS ASKING FOR INFORMATION FROM THE KNOWLEDGE BASE

Follow these steps in order:

**STEP 1 — SEARCH**
Call `search_in_project` to retrieve relevant information based on the user's query.
The database is in ENGLISH — translate the query if needed.

**STEP 2 — SAVE**
Call `save_response_data` with:
- `relevant_chunks`: use the `chunk_ids` array from the search result exactly as-is — these are the UUID strings (e.g. "42288f69-f5ab-44cb-babf-55d4e2485392"). Do NOT pass chunk content or aliases.
- `relevant_images`: copy the `relevant_images` array from the search result as-is (each item has `url` and `content`)
- `proposed_connections`: meaningful relationships identified between chunks/documents

**STEP 3 — RESPOND**
Write your answer in **rich Markdown** in the **same language the user used**.
Base it ONLY on the retrieved data. Do not invent information.
Use headings, bullet points, bold text, tables where appropriate.

**IMAGE RULES (follow if `relevant_images` is non-empty):**
- Embed images inline where they are contextually relevant, using standard Markdown: `![description](url)`
- Place each image immediately after the paragraph it illustrates — alternate text and images for a visual, readable narrative.
- Do NOT cluster all images at the end. Spread them naturally through the response.
- Do NOT include a separate "Images" section or list of image links.
- Do NOT offer the user to download images or open them — the UI already provides downloadable thumbnails.

**CHUNK RULES (always apply):**
- Do NOT list chunk IDs, UUIDs, or aliases anywhere in your response.
- Do NOT tell the user how many chunks you found, which chunks are related, or offer to show "other related chunks".
- Do NOT say things like "Altri chunk rilevanti collegati:", "Other related chunks:", or similar.
- Just write the answer naturally. The UI shows chunk badges and connections separately.

If nothing relevant is found, call `save_response_data` with empty arrays and say so clearly.

---

**RULE:** Never call tools for conversational messages. Never skip tools when the user needs information.
$projectInstructionsBlock
INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }
}
