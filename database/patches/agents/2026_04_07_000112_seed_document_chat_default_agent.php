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
You assist users with the document "{{ document.1.name }}" (project "{{ document.1.project_name }}").
You have access to a search sub-agent backed by `search_chunks` scoped to this document.

## SCOPE
- Use ONLY the content of this document as factual ground truth.
- Do not invent content. Do not use external knowledge for factual answers.

## MODE 1 — SMALL TALK
For conversational messages, reply naturally without retrieval and return empty `relevant_chunks` and `relevant_images`.

## MODE 2 — RETRIEVAL
For factual questions about the document:
1. Deconstruct the request into 1-5 focused search angles.
2. Call the search sub-agent ONCE per turn with all angles in one call.
3. Use returned chunk_ids to populate `relevant_chunks`.
4. Compose the final `response` in rich Markdown in the user's language.
5. Embed relevant images inline with `![description](url)` and include them in `relevant_images`.
6. Never expose chunk UUIDs in the `response` text.
PROMPT;

        $agent = AiAgent::query()->updateOrCreate(
            ['slug' => 'document-chat-default'],
            [
                'name' => 'Document Chat (default)',
                'description' => 'Default chat agent scoped to a single document.',
                'type' => 'chat',
                'scope' => 'document',
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
                    'default_for' => 'document_chat',
                    'chat' => [
                        'include_relevant_chunks' => true,
                        'include_relevant_images' => true,
                    ],
                ],
            ],
        );

        $subAgent = AiAgent::query()->where('slug', 'document-search-default')->first();
        if ($subAgent) {
            $tool = AiAgentTool::query()->updateOrCreate(
                ['key' => 'sub_agent:document-search-default'],
                [
                    'kind' => 'sub_agent',
                    'sub_agent_id' => $subAgent->id,
                    'label' => 'Sub-Agent: Document Search (default)',
                    'description' => 'Delegates retrieval to the document-search-default agent.',
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
        AiAgent::query()->where('slug', 'document-chat-default')->forceDelete();
        AiAgentTool::query()->where('key', 'sub_agent:document-search-default')->delete();
    }
};
