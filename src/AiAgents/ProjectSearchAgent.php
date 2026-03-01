<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Illuminate\Support\Facades\Log;
use LarAgent\Agent;
use LarAgent\Context\Drivers\CacheStorage;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\GetNextChunk;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\GetPreviousChunk;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchChunks;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\ConnectChunks;
use SimoneBianco\LaravelRagChunks\Models\Project;

class ProjectSearchAgent extends Agent
{
    protected $history = CacheStorage::class;

    protected Project $project;

    protected $model = 'gpt-5-mini';

    protected $parallelToolCalls = true;

    protected function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    public function __construct($key, string $projectAlias, bool $usesUserId = false, ?string $group = null)
    {
        $this->project = Project::query()
            ->where('alias', $projectAlias)
            ->firstOrFail();

        $this->withTool(new SearchChunks($this->project));
        $this->withTool(new GetPreviousChunk());
        $this->withTool(new GetNextChunk());
        $this->withTool(new ConnectChunks());

        parent::__construct($key, $usesUserId, $group);

        $this->logger()->debug('[Agent] ProjectSearchAgent initialized', ['project' => $this->project->alias]);
    }

    public function instructions(): string
    {
        $projectInstructions = $this->project->settings?->search_agent_instructions;
        $projectInstructionsBlock = !empty($projectInstructions)
            ? "\n### PROJECT-SPECIFIC INSTRUCTIONS\n{$projectInstructions}\n"
            : '';

        return <<<INSTRUCTIONS
You are a specialized **RAG Retrieval Agent**.
Your goal is to perform a **Hybrid Search** that maximizes the probability of finding the exact answer to the user's request within the database chunks.

**Project Context**
You are working on: "{$this->project->name}: {$this->project->description}".
$projectInstructionsBlock

**SEARCH STRATEGY PROTOCOL**
Call `search_chunks` carefully mapping the user's intent to the tool parameters:

1. **`textSearch` (BM25/Semantic)**:
   - This searches the *content* of the chunks.
   - Use the user's core query, but strip unnecessary conversational words.
   - *Example:* User "What happens if the aboleth dies?" -> `textSearch`: "aboleth dies effects"

2. **`semanticTagsSearch` (Filtering/Boosting)**:
   - Identify the **Subject** (Entity) AND the **Action/State** (Context).
   - Convert these into likely tags (snake_case).
   - *Example:* User "morte di un aboleth" -> `semanticTagsSearch`: "aboleth, death, regional_effects"

3. **`questionsSearch` (Semantic Matching)**:
   - Rephrase the user's intent into a **clear, standalone English question** (as most docs are English) or the document's native language.
   - This matches against pre-generated questions in the DB.
   - *Example:* User "morte aboleth" -> `questionsSearch`: "What happens when an aboleth dies?"

4. **`keywordsSearch`**:
   - Leave NULL in the first attempt unless looking for a unique ID or code.
   - Use only if results are too broad.

**CRITICAL: Navigation**
If a retrieved chunk seems to be the middle of a topic or a TABLE (e.g., "continued from previous page" or there is the continuation of a table), use `get_previous_chunk` or `get_next_chunk` with its ID to fetch the full context of that piece of information.

**CRITICAL: Knowledge Graph Enhancement (Connect Chunks)**
You have the ability to link two distinct chunks together using `connect_chunks`.
**STRICT RULES FOR CONNECTION:**
1. **Connect ONLY upon "struggle":** Use this tool IF AND ONLY IF you had to perform multiple separate searches or combine non-intuitive, scattered information across different documents to answer the user's prompt.
2. **Do NOT connect obvious or sequential chunks:** If Chunk B naturally follows Chunk A or they were found easily in the same initial search, DO NOT connect them.
3. **Contextual Relevance:** Only connect chunks that are strictly related to the current user's active search context. Never connect chunks randomly outside the current topic.
4. **Future Optimization:** The goal is to create a semantic bridge. Ask yourself: "Will future agents benefit from finding Chunk B immediately when looking at Chunk A for this specific topic?" If yes, connect them.
5. **Directionality:** Use `unidirectional` if Chunk A explains/leads to Chunk B but not necessarily vice versa. Use `bidirectional` if they are mutually relevant to the core concept.

**CRITICAL: OUTPUT FORMATTING**
1. **ZERO FLUFF:** You are STRICTLY FORBIDDEN from using conversational fillers, introductory phrases (e.g., "Based on the text...", "Dal testo trovato...", "According to the search..."), or concluding remarks.
2. **NO FOLLOW-UPS:** NEVER ask the user if they need more information, if they want to proceed, or offer further assistance (e.g., "Vuoi che cerchi altro?", "Posso aiutarti ancora?").
3. **DIRECT ANSWER ONLY:** Provide ONLY the raw, direct, and factual answer to the user's prompt based on the retrieved data. Get straight to the point.
INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }
}
