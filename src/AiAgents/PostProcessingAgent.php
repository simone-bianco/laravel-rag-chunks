<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use LarAgent\Agent;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\PostProcessingAgentResponseDTO;

class PostProcessingAgent extends Agent
{
    protected string $previousChunkTags = '';

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
            'questions' => [
                'type' => 'array',
                'description' => 'Set of questions that can be used to retrieve the chunk of text',
                'items' => [
                    'type' => 'string',
                ]
            ],
            'semantic_tags' => [
                'type' => 'array',
                'description' => 'Set of tags that describe better the content of the chunk of text',
                'items' => [
                    'type' => 'string',
                ]
            ],
        ],
        'required' => ['semantic_tags', 'questions'],
    ];

    public function withDocumentContext(string $context): self
    {
        if (!empty($context)) {
            $this->documentContext = "\n### DOCUMENT CONTEXT\n$context";
        }

        return $this;
    }

    public function withPreviousChunkTags(?string $previousChunkTags): self
    {
        if (!empty($previousChunkTags)) {
            $this->previousChunkTags = "\n### PREVIOUS CHUNK TAGS (take into account when generating new tags, but do not repeat them if they are not relevant to the current chunk)\n$previousChunkTags";
        }

        return $this;
    }

    public function instructions(): string
    {
        return <<<INSTRUCTIONS
### PERSONA
You are an expert optimizer for RAG (Retrieval-Augmented Generation) systems.

### GOAL
Your task is to analyze the provided chunk of text and extract a set of highly relevant questions and tags that describe its content.

### TAGGING RULES
1. **Format**: All tags must be strictly **LOWERCASE** and formatted as **SLUGS** (slug_case).
   - Correct: `artificial_intelligence`, `laravel_framework`, `user_authentication`
   - Incorrect: `Artificial Intelligence`, `laravel-framework`, `User Authentication`
2. **Specificity**: Focus on specific entities, key concepts, technologies, or unique topics found in the text. Avoid generic filler words.
3. **Retrieval**: Choose tags that would allow a search engine to find this specific chunk easily among many others.

### QUESTIONS RULES
1. **Reverse Engineering**: Formulate 1-5 questions that a user would naturally ask where *this specific chunk* provides the best answer.
2. **Accuracy**: Ensure the questions are directly answerable by the information contained in the text. Do not hallucinate information not present in the chunk.
3. **Variety**: Aim for a mix of conceptual questions (e.g., "What is X?") and procedural/specific questions (e.g., "How do I configure Y?").
4. **Self-Contained**: Questions should be understandable without needing previous conversation context.

$this->documentContext
$this->previousChunkTags
INSTRUCTIONS;
    }

    public function respondAndGetFormattedResults(string $text): PostProcessingAgentResponseDTO
    {
        $response = $this->respond($text);
        return new PostProcessingAgentResponseDTO(
            tags: $response['semantic_tags'] ?? [],
            questions: $response['questions'] ?? []
        );
    }

    public function prompt($message)
    {
        return $message;
    }
}
