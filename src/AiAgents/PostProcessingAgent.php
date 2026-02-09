<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Illuminate\Support\Arr;
use LarAgent\Agent;
use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Core\Contracts\Message as MessageInterface;
use Throwable;

class PostProcessingAgent extends Agent
{
    protected $history = CacheStorage::class;
    protected array $chunksByKey = [];

    public function __construct(
        $key,
        array $chunks,
        protected array $allowedTagsByType = [],
        bool $usesUserId = false,
        ?string $group = null,
        protected ?array $config = null,
        protected ?string $documentContext = null
    ) {
        $this->chunksByKey = Arr::mapWithKeys($chunks, function ($chunk, $index) {
            return ["chunk_$index" => $chunk];
        });
        $config = config('rag_chunks.ai_agents.semantic_tagger', []);
        parent::__construct($key, $usesUserId, $group);
        $this->provider = $config['provider'] ?? 'openai';
        $this->model = $config['model'] ?? 'gpt-4.1-nano';
    }

    protected function afterResponse(MessageInterface $message)
    {
        dd($message->getMetadata());
    }

    /**
     * @return array
     */
    /**
     * @return array
     */
    protected function getResponseSchema(): array
    {
        $allowedTagsSchema = [];
        foreach ($this->allowedTagsByType as $type => $tags) {
            $allowedTagsSchema[$type] = [
                'type' => 'array',
                'description' => 'List of tags of type "' . $type . '"',
                'items' => [
                    'type' => 'string',
                    'enum' => array_values($tags),
                ],
            ];
        }

        $chunksObjects = array_map(function ($chunk) use ($allowedTagsSchema) {
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
                    'allowed_tags' => [
                        'type' => 'object',
                        'properties' => $allowedTagsSchema,
                        'required' => array_keys($allowedTagsSchema),
                        'additionalProperties' => false,
                    ],
                ],
                'required' => ['tags', 'questions', 'allowed_tags'],
                'additionalProperties' => false
            ];
        }, $this->chunksByKey);

        return [
            'type' => 'object',
            'description' => 'All the chunks with tags and questions',
            'properties' => $chunksObjects,
            'required' => array_keys($chunksObjects),
            'additionalProperties' => false
        ];
    }

    public function structuredOutput()
    {
        return $this->getResponseSchema();
    }

    public function withDocumentContext(string $context): self
    {
        if (!empty($context)) {
            $this->documentContext = "\n### DOCUMENT CONTEXT\n$context";
        }

        return $this;
    }

    public function withChunks(array $chunks): self
    {

        return $this;
    }

    public function instructions(): string
    {
        return <<<INSTRUCTIONS
### PERSONA
You are an expert optimizer for RAG (Retrieval-Augmented Generation) systems.

### GOAL
Your task is to analyze the provided chunks of text and extract a set of highly relevant questions and tags that describe its content.
The chunks belong to the same document, so you can get the whole context.

### UNIVERSAL RULES
1. **Context-Understand**: Giving you more contiguous chunks will allow you to understand better the context, especially for abstract data like tables.
2. **Same-Order**: The output MUST BE in the same order as the input chunks.

### TAGGING RULES
1. **Format**: All tags must be strictly **LOWERCASE** and formatted as **SLUGS** (slug_case).
   - Correct: `artificial_intelligence`, `laravel_framework`, `user_authentication`
   - Incorrect: `Artificial Intelligence`, `laravel-framework`, `User Authentication`
2. **Specificity**: Focus on specific entities, key concepts, technologies, or unique topics found in the text. Avoid generic filler words like 'role play game' or 'game'.
3. **Retrieval**: Choose tags that would allow a search engine to find this specific chunk easily among many others.

### QUESTIONS RULES
1. **Reverse Engineering**: Formulate 1-5 questions that a user would naturally ask where *this specific chunk* provides the best answer.
2. **Accuracy**: Ensure the questions are directly answerable by the information contained in the text. Do not hallucinate information not present in the chunk.
3. **Variety**: Aim for a mix of conceptual questions (e.g., "What is X?") and procedural/specific questions (e.g., "How do I configure Y?"), but don't repeat same questions.

$this->documentContext
INSTRUCTIONS;
    }

    /**
     * @throws Throwable
     */
    public function respondAndGetFormattedResults()
    {
        return array_values($this->respond('proceed'));
//        return new PostProcessingAgentResponseDTO(
//            tags: $response['tags'] ?? [],
//            questions: $response['questions'] ?? []
//        );
    }

    public function prompt($message)
    {
        return $message;
    }
}
