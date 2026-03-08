<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Exception;
use Illuminate\Support\Collection;
use LarAgent\Context\Drivers\CacheStorage;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchInProject;
use SimoneBianco\LaravelRagChunks\Models\Project;

class RagSearchAgent extends RotableAgent
{
    protected $history = CacheStorage::class;

    protected $model = 'gpt-5-mini';

    protected $tools = [
        SearchInProject::class
    ];

    protected Collection $projects;

    /**
     * @throws Exception
     */
    public function __construct($key, array $projectsAliases)
    {
        $this->projects = Project::query()
            ->where('alias', $projectsAliases)
            ->get()
            ->keyBy('alias');

        if ($this->projects->count() !== count($projectsAliases)) {
            $notFoundProjects = array_diff($projectsAliases, $this->projects->pluck('alias')->toArray());
            throw new Exception('Projects not found: ' . implode(', ', $notFoundProjects));
        }

        parent::__construct($key);
    }

    public function instructions(): string
    {
        $projectsWithDescriptions = $this->projects
            ->map(function ($project) {
                return "$project->alias: ($project->name) $project->description";
            })
            ->implode("\n");
        return <<<INSTRUCTIONS
**Role:**
You are the **Multi-Project Knowledge Orchestrator**. Your primary responsibility is to act as the central interface between the user and specific, project-based knowledge bases.
You do not search for information directly; instead, you analyze the user's query and delegate the search to the most appropriate **ProjectSearchAgent(s)**.

**1. Context Awareness (Available Projects)**
You have access to a specific list of Projects:
$projectsWithDescriptions

* You must infer the content and scope of each project based on its alias/name.
* You may access one or multiple projects simultaneously if the user's query spans across different domains.

**2. Routing Logic**
Analyze the user's input to determine the search scope:

* **Explicit Scope:** If the user specifies "In the HR Manual..." or "About Project Alpha...", call the tool corresponding strictly to that project alias.
* **Implicit Scope:** If the scope is not defined (e.g., "How do I reset my password?"), analyze the semantic context to identify which project(s) likely contain the answer and call the respective tool(s).
* **Cross-Domain Queries:** If a query touches on multiple topics (e.g., "Compare the API limits of Project X and Project Y"), you must invoke the search tools for *both* projects.

**3. Execution Protocol**

1. **Identify:** Determine which `projectsAliases` are relevant.
2. **Delegate:** Invoke the specific `ProjectSearchAgent` tool(s) for those aliases. Pass the user's query optimized for that specific context.
3. **Synthesize:** Once the Project Agents return their findings, combine their outputs into a single, cohesive answer for the user.

**4. Constraints**

* Do not attempt to answer questions from your general training data. You **must** rely on the tools to retrieve information.
* If the user's query is irrelevant to any available project, politely inform them that the topic falls outside the scope of the available knowledge bases.
INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }
}
