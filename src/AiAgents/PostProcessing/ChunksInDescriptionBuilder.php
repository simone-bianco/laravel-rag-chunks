<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing;

use SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing\Contracts\AgentBuilderStrategy;

class ChunksInDescriptionBuilder implements AgentBuilderStrategy
{
    public function buildSchemaProperties(array $chunksByKey): array
    {
        return array_map(function ($chunk) {
            return [
                'type' => 'object',
                'description' => $chunk,
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
        return 'do your best';
    }
}
