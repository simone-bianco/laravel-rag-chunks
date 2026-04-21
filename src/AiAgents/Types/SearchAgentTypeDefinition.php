<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents\Types;

use SimoneBianco\LaravelAiAgents\DTOs\AgentDefinitionData;
use SimoneBianco\LaravelAiAgents\Models\AiAgent;

final class SearchAgentTypeDefinition extends AgentTypeDefinition
{
    public function key(): string
    {
        return 'search';
    }

    public function label(): string
    {
        return 'Search Agent';
    }

    public function description(): string
    {
        return 'Retrieval agent that performs RAG search and returns structured chunk results.';
    }

    public function allowedToolKeys(): array
    {
        return [
            'search_chunks',
            'get_searches_results',
            'get_chunks_by_aliases',
            'connect_chunks',
            'save_response_data',
            'search_in_project',
            'search_in_allowed_projects',
        ];
    }

    public function allowedSubAgentTypes(): array
    {
        return [];
    }

    public function formSections(): array
    {
        return [
            'identity',
            'model',
            'scope_bindings',
            'system_prompt',
            'variables',
            'tools_search',
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
        return [
            'name' => 'agent_response',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'results' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'chunk_id' => ['type' => 'string'],
                                'content_snippet' => ['type' => 'string'],
                            ],
                            'required' => ['chunk_id', 'content_snippet'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['results'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    public function validateDefinition(AgentDefinitionData $data): void
    {
        // Hook for additional type-specific validation.
    }
}
