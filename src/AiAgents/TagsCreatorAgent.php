<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use LarAgent\Context\Drivers\CacheStorage;
use SimoneBianco\LaravelAiAgents\Agents\RotableAgent;
use SimoneBianco\LaravelRagChunks\Models\Project;

class TagsCreatorAgent extends RotableAgent
{
    protected $history = CacheStorage::class;

    protected Project $project;

    protected $model = 'gpt-5.4-mini';
    protected string $additionalInstructions = '';

    protected $responseSchema = [
        'name' => 'tags_with_type',
        'schema' => [
            'type' => 'object',
            'properties' => [
                'tags' => [
                    'type' => 'array',
                    'description' => 'Relevant tags grouped by type. Keep the result minimal: max 5 types and max 15 tags per type.',
                    'items' => [
                        'type' => 'object',
                        'description' => 'Single tag data',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'Tag name in snake_case, lowercase, without accents, spaces or hyphens.'
                            ],
                            'type' => [
                                'type' => 'string',
                                'description' => 'Stable category of the tag, such as content_type, rule, entity, concept, mechanic, topic, tool, code, generic.'
                            ]
                        ],
                        'required' => ['name', 'type'],
                        'additionalProperties' => false,
                    ]
                ],
            ],
            'required' => ['tags'],
            'additionalProperties' => false,
        ],
        'strict' => true,
    ];

    public function __construct($key, string $projectAlias, bool $usesUserId = false, ?string $group = null)
    {
        $this->project = Project::query()
            ->select(['alias', 'name', 'description'])
            ->where('alias', $projectAlias)
            ->firstOrFail();

        parent::__construct($key, $usesUserId, $group);
    }

    public function withAdditionalInstructions(?string $additionalInstructions = ''): self
    {
        $this->additionalInstructions = $additionalInstructions;

        return $this;
    }

    public function instructions(): string
    {
        $currentTagsByType = json_encode($this->project->getTagsSlugsKeyedByTypes());

        return <<<INSTRUCTIONS
You are a tag creator for a Retrieval-Augmented Generation (RAG) system.

Your task is to analyze the provided content and return only the tags that improve search, filtering, and retrieval. Do not summarize the content.

Project Context:
- Name: {$this->project->name}
- Description: {$this->project->description}

Current Existing Tags, grouped by type:
{$currentTagsByType}

Tagging Rules:
1. Prefer existing tags when they accurately match the content.
2. Create new tags only when existing tags are not enough.
3. Use only tags that are directly supported by the content.
4. Do not add generic project-level tags unless the chunk specifically discusses them.
5. Keep the output minimal: usually 3-8 tags total are enough.
6. Never exceed 5 distinct types.
7. Never exceed 15 tags per type.
8. Tag names must be lowercase snake_case, with no spaces, hyphens, accents, or punctuation.
9. Use stable and reusable tag types. Prefer types such as:
   - content_type
   - rule
   - mechanic
   - entity
   - topic
   - tool
   - code
   - generic
10. Avoid weak tags that do not help retrieval, such as broad genres, moods, marketing terms, or obvious labels.
11. Avoid duplicate meanings. Do not return multiple tags that express the same concept.
12. If the content is ambiguous, choose fewer and broader tags rather than inventing specific ones.
13. If no useful tag can be assigned, return an empty tags array.

Good tags describe what a user might search for later.
Bad tags merely describe the project in general.

{$this->additionalInstructions}
INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }
}
