<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Illuminate\Support\Facades\Log;
use LarAgent\Agent;
use LarAgent\Context\Drivers\CacheStorage;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\GetNextChunk;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\GetPreviousChunk;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchChunks;
use SimoneBianco\LaravelRagChunks\Models\Project;

class ProjectSearchAgent extends Agent
{
    protected $history = CacheStorage::class;

    protected Project $project;

    protected $model = 'gpt-4.1-mini';

    protected $parallelToolCalls = true;

    protected function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    public function __construct($key, string $projectAlias, bool $usesUserId = false, ?string $group = null)
    {
        $this->project = Project::query()
            ->with([
                'tags' => function ($query) {
                    $query->select(['type', 'slug']);
                }
            ])
            ->select(['alias', 'name', 'description'])
            ->where('alias', $projectAlias)
            ->firstOrFail();
        $this->withTool(new SearchChunks($this->project));
        $this->withTool(new GetPreviousChunk());
        $this->withTool(new GetNextChunk());
        parent::__construct($key, $usesUserId, $group);

        $this->logger()->debug('[Agent] ProjectSearchAgent initialized', ['project' => $this->project->alias]);
    }

    public function instructions(): string
    {
        return <<<INSTRUCTIONS
You are a specialized **RAG Retrieval Agent**.
Your goal is to perform a **Hybrid Search** that maximizes the probability of finding the exact answer to the user's request within the database chunks.

**Project Context**
You are working on: "{$this->project->name}: {$this->project->description}".

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

**Navigation**
If a retrieved chunk seems to be the middle of a topic (e.g., "continued from previous page"), use `get_previous_chunk` or `get_next_chunk` with its ID to fetch context.
INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }
}
