<?php

declare(strict_types=1);

use SimoneBianco\LaravelAiAgents\Models\AiAgent;
use SimoneBianco\LaravelAiAgents\Models\AiAgentTool;
use SimoneBianco\LaravelAiAgents\Models\AiAgentToolBinding;

return new class {
    public bool $transactional = true;

    public function up(): void
    {
        $systemPrompt = <<<'PROMPT'
You assist users inside project "{{ project.1.name }}: {{ project.1.description }}".
{{ document.1.name }}
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
- Identify all relevant search angles.

### 2) Build comprehensive search angles
- Create 1-5 focused search items in English covering ALL identified aspects.
- Always include at least one visual angle when visual context would be helpful.

### 3) Multi-search tool call
- Call `search_in_project` exactly ONCE per user turn.
- Send ALL angles in a SINGLE `searches` array.
- Always send `persistentKey`. Reuse the same key to refine the same research thread; new key for fresh threads.

### 4) Use results
- Tool output is `{ results: [...] }`. Use `chunk_ids` to populate final `relevant_chunks`.
- Use returned images plus chunk image URLs.

### 5) Compose final answer
- Write `response` in rich Markdown, same language as user.
- Never add preambles like "Ecco cosa ho trovato".
- Embed images inline with `![description](url)`; never output raw URL lists.
- Include all used chunk UUIDs in `relevant_chunks`, but never print UUIDs in `response`.
- Include each embedded image in `relevant_images` as `{url, content}`.
PROMPT;

        $agent = AiAgent::query()->updateOrCreate(
            ['slug' => 'project-chat-default'],
            [
                'name' => 'Project Chat (default)',
                'description' => 'Default chat agent scoped to a single project.',
                'type' => 'chat',
                'scope' => 'project',
                'provider' => 'openai',
                'model' => 'gpt-5.4',
                'system_prompt' => $systemPrompt,
                'max_completion_tokens' => 16384,
                'parallel_tool_calls' => false,
                'response_format' => 'json_schema',
                'streaming_mode' => 'sync',
                'is_system' => true,
                'is_locked' => true,
                'metadata' => [
                    'default_for' => 'project_chat',
                    'chat' => [
                        'include_relevant_chunks' => true,
                        'include_relevant_images' => true,
                    ],
                ],
            ],
        );

        $subAgent = AiAgent::query()->where('slug', 'project-search-default')->first();
        if ($subAgent) {
            $tool = AiAgentTool::query()->updateOrCreate(
                ['key' => 'sub_agent:project-search-default'],
                [
                    'kind' => 'sub_agent',
                    'sub_agent_id' => $subAgent->id,
                    'label' => 'Sub-Agent: Project Search (default)',
                    'description' => 'Delegates retrieval to the project-search-default agent.',
                    'is_enabled' => true,
                ],
            );

            AiAgentToolBinding::query()->updateOrCreate(
                ['agent_id' => $agent->id, 'tool_id' => $tool->id],
                ['position' => 0, 'sub_agent_history_mode' => 'stateless'],
            );
        }
    }

    public function down(): void
    {
        AiAgent::query()->where('slug', 'project-chat-default')->forceDelete();
        AiAgentTool::query()->where('key', 'sub_agent:project-search-default')->delete();
    }
};
