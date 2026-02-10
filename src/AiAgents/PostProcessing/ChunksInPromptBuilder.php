<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing;

use SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing\Contracts\AgentBuilderStrategy;

class ChunksInPromptBuilder implements AgentBuilderStrategy
{
    public function buildSchemaProperties(array $chunksByKey): array
    {
        return array_map(function () {
            return [
                'type' => 'object',
                'description' => 'List of keyed chunks with questions and tags',
                'properties' => [
                    'questions' => [
                        'type' => 'array',
                        'description' => 'Set of questions that can be used to retrieve the chunk of text',
                        'items' => [
                            'type' => 'string',
                        ]
                    ],
                    'tags' => [
                        'type' => 'array',
                        'description' => 'Set of SEMANTIC tags that describe better the content of the chunk of text',
                        'items' => [
                            'type' => 'string',
                        ]
                    ],
                ],
                'required' => ['tags', 'questions'],
                'additionalProperties' => false
            ];
        }, $chunksByKey);
    }

    public function buildPrompt(array $chunksByKey): string
    {
        return json_encode($chunksByKey, JSON_UNESCAPED_UNICODE);
    }
}
