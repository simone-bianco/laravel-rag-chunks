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
            ['slug' => 'multi-project-search-default'],
            [
                'name' => 'Multi-Project Search (default)',
                'description' => 'Default search agent that can search across an allowlist of projects.',
                'type' => 'search',
                'scope' => null,
                'provider' => 'openai',
                'model' => 'gpt-5.4',
                'system_prompt' => <<<'PROMPT'
You are a multi-project search assistant.
Use `search_in_allowed_projects` to retrieve the most relevant chunks across the allowed projects.
Do not invent content. Use only retrieved chunks.
PROMPT,
                'parallel_tool_calls' => false,
                'response_format' => 'json_schema',
                'streaming_mode' => 'sync',
                'is_system' => true,
                'is_locked' => true,
                'metadata' => ['default_for' => 'multi_project_search'],
            ],
        );

        $tool = AiAgentTool::query()->where('key', 'search_in_allowed_projects')->first();
        if ($tool) {
            AiAgentToolBinding::query()->updateOrCreate(
                ['agent_id' => $agent->id, 'tool_id' => $tool->id],
                ['position' => 0, 'sub_agent_history_mode' => 'stateless'],
            );
        }
    }

    public function down(): void
    {
        AiAgent::query()->where('slug', 'multi-project-search-default')->forceDelete();
    }
};
