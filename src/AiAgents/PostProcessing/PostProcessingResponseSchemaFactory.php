<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing;

class PostProcessingResponseSchemaFactory
{
    /**
     * @param array<string, array<int, string>> $tagsByType
     * @param array<int, string> $allowedFigurePaths
     * @return array<string, mixed>
     */
    public static function build(
        string $contentDescription,
        array $tagsByType = [],
        bool $createIndex = false,
        array $allowedFigurePaths = [],
    ): array {
        $deterministicTagProperties = [];
        $deterministicTagRequired = [];

        if (!empty($tagsByType)) {
            foreach ($tagsByType as $type => $tags) {
                $deterministicTagProperties["tags_$type"] = [
                    'type' => 'array',
                    'description' => "Deterministic tags of type '$type'. MUST pick ONLY from the provided slug enum values (kebab-case identifiers). Leave empty if none apply.",
                    'items' => [
                        'type' => 'string',
                        'enum' => $tags,
                    ],
                ];
                $deterministicTagRequired[] = "tags_$type";
            }
        }

        $figurePathProperty = [
            'type' => 'string',
            'description' => 'The path to the figure/image. MUST be exactly one of the provided input figure_path values, or empty string "" when there is no relevant figure.',
        ];
        $figurePathProperty['enum'] = !empty($allowedFigurePaths)
            ? [...$allowedFigurePaths, '']
            : [''];

        $chapterProperty = [];
        $chapterRequired = [];
        if ($createIndex) {
            $chapterProperty = [
                'chapter_title' => [
                    'type' => 'string',
                    'description' => 'Title of the chapter',
                ],
            ];
            $chapterRequired = ['chapter_title'];
        }

        return [
            'type' => 'object',
            'description' => 'List of dynamically sized, ordered chunks with questions and tags',
            'properties' => [
                'active_context_for_next_chunking' => [
                    'type' => 'string',
                    'description' => 'Short high-level context label to help the next batch keep continuity (for example current section/chapter topic). Keep it concise; return empty string if not useful.',
                ],
                'useful_info_for_next_chunking' => [
                    'type' => 'string',
                    'description' => 'Put there useful information for next chunking, for instance if the last chunk you received is cut and the next part will be handled by the next agent; keep as short as possible',
                ],
                'chunks' => [
                    'type' => 'array',
                    'description' => 'Dynamically processed chunks, forming highly cohesive atomic semantic units.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => [
                                'type' => 'string',
                                'description' => $contentDescription,
                            ],
                            'tags' => [
                                'type' => 'array',
                                'description' => 'List of 5 to 10 semantic tags for RAG retrieval. CRITICAL: The main subject/entity of the chunk MUST be included as a tag. Must be strictly LOWERCASE and SLUG_CASE. Tag both the subject and the action/event. Do not generate fewer than 5 tags.',
                                'items' => ['type' => 'string'],
                            ],
                            'questions' => [
                                'type' => 'array',
                                'description' => 'List of 3 to 5 reverse-engineered questions that this specific chunk answers perfectly. CRITICAL: Every single question MUST explicitly include the subject or entity name of the chunk. Do not generate fewer than 3 questions.',
                                'items' => ['type' => 'string'],
                            ],
                            'figure_path' => [
                                ...$figurePathProperty,
                            ],
                            ...$chapterProperty,
                            ...$deterministicTagProperties,
                        ],
                        'required' => ['content', 'tags', 'questions', 'figure_path', ...$chapterRequired, ...$deterministicTagRequired],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['active_context_for_next_chunking', 'useful_info_for_next_chunking', 'chunks'],
            'additionalProperties' => false,
        ];
    }
}
