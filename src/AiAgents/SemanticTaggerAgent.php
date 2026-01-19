<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use LarAgent\Agent;

class SemanticTaggerAgent extends Agent
{
    public function __construct(
        $key,
        bool $usesUserId = false,
        ?string $group = null,
        protected ?array $config = null,
        protected ?string $documentContext = null
    ) {
        $config = config('rag_chunks.ai_agents.semantic_tagger', []);
        parent::__construct($key, $usesUserId, $group);
        $this->provider = $config['provider'] ?? 'openai';
        $this->model = $config['model'] ?? 'gpt-4.1-nano';
    }

    protected $responseSchema = [
        'type' => 'object',
        'properties' => [
            'semantic_tags' => [
                'type' => 'array',
                'description' => 'Set of tags that describe better the content of the chunk of text',
                'items' => [
                    'type' => 'string',
                ]
            ]
        ],
        'required' => ['semantic_tags'],
    ];

    public function withDocumentContext(string $context): self
    {
        $this->documentContext = "\n### DOCUMENT CONTEXT\n$context";

        return $this;
    }

    public function instructions(): string
    {
        return <<<INSTRUCTIONS
### PERSONA
You are an expert Semantic Tagger optimized for RAG (Retrieval-Augmented Generation) systems.

### GOAL
Your task is to analyze the provided chunk of text and extract a set of highly relevant tags that describe its content.

### TAGGING RULES
1. **Format**: All tags must be strictly **LOWERCASE** and formatted as **SLUGS** (slug_case).
   - Correct: `artificial_intelligence`, `laravel_framework`, `user_authentication`
   - Incorrect: `Artificial Intelligence`, `laravel-framework`, `User Authentication`
2. **Specificity**: Focus on specific entities, key concepts, technologies, or unique topics found in the text. Avoid generic filler words.
3. **Retrieval**: Choose tags that would allow a search engine to find this specific chunk easily among many others.
$this->documentContext
INSTRUCTIONS;
    }

    public function getFormattedTags(string $text): string
    {
        return implode('+', $this->respond($text)['semantic_tags'] ?? []);
    }

    public function prompt($message)
    {
        return $message;
    }
}
