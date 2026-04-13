<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents\Types;

use SimoneBianco\LaravelAiAgents\DTOs\AgentDefinitionData;
use SimoneBianco\LaravelAiAgents\Models\AiAgent;

final class ChatAgentTypeDefinition extends AgentTypeDefinition
{
    public function key(): string
    {
        return 'chat';
    }

    public function label(): string
    {
        return 'Chat Agent';
    }

    public function description(): string
    {
        return 'Conversational agent that uses RAG retrieval sub-agents to answer user questions.';
    }

    public function allowedToolKeys(): array
    {
        return ['search_in_project', 'search_in_allowed_projects', 'search_chunks'];
    }

    public function allowedSubAgentTypes(): array
    {
        return ['search'];
    }

    public function formSections(): array
    {
        return [
            'identity',
            'model',
            'scope_bindings',
            'system_prompt',
            'variables',
            'response_options',
            'tools_chat',
            'revisions',
        ];
    }

    public function defaultModel(): string
    {
        return 'gpt-5.4';
    }

    public function defaultStreamingMode(): string
    {
        return 'sync';
    }

    public function buildResponseSchema(AiAgent $agent): ?array
    {
        $metadata = is_array($agent->metadata ?? null) ? $agent->metadata : [];
        $chatMeta = is_array($metadata['chat'] ?? null) ? $metadata['chat'] : [];

        $isSync = ($agent->streaming_mode?->value ?? 'sync') === 'sync';
        $includeChunks = ((bool) ($chatMeta['include_relevant_chunks'] ?? true)) && $isSync;
        $includeImages = ((bool) ($chatMeta['include_relevant_images'] ?? true)) && $isSync;

        $properties = [
            'response' => [
                'type' => 'string',
                'description' => "Assistant's response in Markdown.",
            ],
        ];
        $required = ['response'];

        if ($includeChunks) {
            $properties['relevant_chunks'] = [
                'type' => 'array',
                'description' => 'Chunk UUIDs used to build the response.',
                'items' => ['type' => 'string'],
            ];
            $required[] = 'relevant_chunks';
        }

        if ($includeImages) {
            $properties['relevant_images'] = [
                'type' => 'array',
                'description' => 'Relevant image objects.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                    ],
                    'required' => ['url', 'content'],
                    'additionalProperties' => false,
                ],
            ];
            $required[] = 'relevant_images';
        }

        return [
            'name' => 'agent_response',
            'schema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    public function validateDefinition(AgentDefinitionData $data): void
    {
        // Hook for additional type-specific validation; the DTO already enforces structure.
    }
}
