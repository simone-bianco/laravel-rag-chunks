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
                    'description' => 'All the tags, max 5 types and 15 tags per type (but try to keep that minimal)',
                    'items' => [
                        'type' => 'object',
                        'description' => 'single tag data',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'name of the tag'
                            ],
                            'type' => [
                                'type' => 'string',
                                'description' => 'Category of the tag (e.g. generic, code, rule, etc...)',
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
You are an expert content analyzer and categorizer for a Retrieval-Augmented Generation (RAG) system.
Your task is to analyze the provided content and extract or generate the most relevant tags to optimize search and document retrieval.

Project Context:
- Name: {$this->project->name}
- Description: {$this->project->description}

Current Existing Tags (grouped by type):
{$currentTagsByType}

Rules for Tag Generation:
1. Prioritize using the existing tags provided in the JSON above if they fit the content.
2. If the current tags are insufficient, you may generate new ones, keeping them concise and highly relevant.
3. Classify each tag with a relevant 'type' (e.g., 'concept', 'technology', 'entity', 'rule', 'generic').
4. Do not exceed 5 distinct types.
5. Do not exceed 15 tags per type.
6. Keep the overall number of tags to a minimum; only include tags that add real search value.

{$this->additionalInstructions}
INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }
}
