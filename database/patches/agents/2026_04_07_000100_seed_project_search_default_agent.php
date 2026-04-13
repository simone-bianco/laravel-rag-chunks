<?php

declare(strict_types=1);

use SimoneBianco\LaravelAiAgents\Models\AiAgent;
use SimoneBianco\LaravelAiAgents\Models\AiAgentTool;
use SimoneBianco\LaravelAiAgents\Models\AiAgentToolBinding;

return new class {
    public bool $transactional = true;

    public function up(): void
    {
        $agent = AiAgent::query()->updateOrCreate(
            ['slug' => 'project-search-default'],
            [
                'name' => 'Project Search (default)',
                'description' => 'Default search agent scoped to a single project.',
                'type' => 'search',
                'scope' => 'project',
                'provider' => 'openai',
                'model' => 'gpt-5.4',
                'system_prompt' => <<<'PROMPT'
You are a search assistant scoped to a single project.
When asked a question, call `search_in_project` with the user's query and return the most relevant results found.
Do not invent content. Use only retrieved chunks.
PROMPT,
                'parallel_tool_calls' => false,
                'response_format' => 'json_schema',
                'streaming_mode' => 'sync',
                'is_system' => true,
                'is_locked' => true,
                'metadata' => ['default_for' => 'project_search'],
            ],
        );

        $tool = AiAgentTool::query()->where('key', 'search_in_project')->first();
        if ($tool) {
            AiAgentToolBinding::query()->updateOrCreate(
                ['agent_id' => $agent->id, 'tool_id' => $tool->id],
                ['position' => 0, 'sub_agent_history_mode' => 'stateless'],
            );
        }
    }

    public function down(): void
    {
        AiAgent::query()->where('slug', 'project-search-default')->forceDelete();
    }
};
