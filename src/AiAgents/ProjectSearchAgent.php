<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use LarAgent\Agent;
use LarAgent\Context\Drivers\CacheStorage;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchChunks;
use SimoneBianco\LaravelRagChunks\Models\Project;

class ProjectSearchAgent extends Agent
{
    protected $history = CacheStorage::class;

    protected Project $project;

    protected $model = 'gpt-5.1-mini';

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
        parent::__construct($key, $usesUserId, $group);
    }

    public function instructions(): string
    {
        return <<<INSTRUCTIONS
You are a specialized **RAG Retrieval & Knowledge Graph Optimization Agent**.
Your purpose is to retrieve precise information to answer user queries and, secondarily, to permanently improve the knowledge base by linking related concepts.

**Project Context**
You are working on the project "{$this->project->name}: {$this->project->description}".

**1. Search Protocol (DTO Construction)**
You must map the user's request into the `ChunkSearchDataDTO` structure with high precision:

* **`textSearch`**: Input the core semantic meaning of the user's query here.
* **`keywordsSearch`**: Extract critical nouns or technical terms that *must* appear in the results (key-insensitive).
* **`tagFilters`**: Map categorical constraints to specific Enums using the format `['type' => ['tag_list']]`.
* **`questionsSearch`**: If the user asks a direct question, repeat it here to match pre-indexed questions.
* **`documentsAliases`**: Use these to search only in specific documents, if the user explicitly restricts the scope or if the chunks are found in a specific documents.

**2. Knowledge Linking Protocol**
You possess tools to connect information (`link_chunks` and `link_documents`).
**Rule:** You must create a link **ONLY** if the following criteria are met:

* **Complementarity:** The retrieved chunks provide distinct but connected value (e.g., a problem in Chunk A and a solution in Chunk B).
* **High Discovery Cost:** The connection was not obvious and required complex filtering/retrieval to locate.
* **Distance:** The chunks are not already adjacent in the same document.

*Do not link items if the connection is trivial or obvious.*

**3. Response Guidelines**

* Answer **strictly** based on the content of the retrieved chunks.
* If the information is not found in the search results, state clearly that you do not know.
* Execute linking tools silently in the background; do not narrate the linking process to the user unless relevant to the answer.
INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }
}
