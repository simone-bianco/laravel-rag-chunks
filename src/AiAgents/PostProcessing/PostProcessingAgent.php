<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing;

use Exception;
use Illuminate\Support\Arr;
use LarAgent\Agent;
use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing\Contracts\AgentBuilderStrategy;

class PostProcessingAgent extends Agent
{
    protected $history = CacheStorage::class;
    protected $toolCacheTtl = 60;
    protected array $chunksByKey = [];
    protected $mcpServers = [];
    protected string $documentContext;
    protected bool $chunksInSchema = true;
    protected AgentBuilderStrategy $builderStrategy;
    protected array $config = [];

    public function __construct(
        string $key,
        array $injectConfig = []
    ) {
        parent::__construct($key);

        $config = config('rag_chunks.agents.postprocessor', []);
        $this->config['provider'] = $injectConfig['provider'] ?? $config['provider'] ?? 'openai';
        $this->config['model'] = $injectConfig['model'] ?? $config['model'] ?? 'gpt-4.1-nano';
        $this->chunksInSchema = $injectConfig['chunks_in_schema'] ?? $config['chunks_in_schema'] ?? true;

        $this->builderStrategy = $this->chunksInSchema
            ? new ChunksInDescriptionBuilder() : new ChunksInPromptBuilder();
    }

    public function withChunks(array $chunks): self
    {
        $this->chunksByKey = Arr::mapWithKeys($chunks, function ($chunk, $index) {
            return ["chunk_$index" => $chunk];
        });

        return $this;
    }

    /**
     * @return array
     */
    protected function getResponseSchema(): array
    {
        $properties = $this->builderStrategy->buildSchemaProperties($this->chunksByKey);
        return [
            'type' => 'object',
            'description' => 'All the chunks with tags and questions',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false
        ];
    }

    public function structuredOutput(): array
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

    public function instructions(): string
    {
        return <<<INSTRUCTIONS
### PERSONA
You are an expert optimizer for RAG (Retrieval-Augmented Generation) systems.

$this->documentContext

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
4. **Wider-Context**: Chunks in the batch are sequential, so that you can have a broader understanding of the context.

### QUESTIONS RULES
1. **Reverse Engineering**: Formulate 1-5 questions that a user would naturally ask where *this specific chunk* provides the best answer.
2. **Accuracy**: Ensure the questions are directly answerable by the information contained in the text. Do not hallucinate information not present in the chunk.
3. **Variety**: Aim for a mix of conceptual questions (e.g., "What is X?") and procedural/specific questions (e.g., "How do I configure Y?"), but don't repeat same questions.
INSTRUCTIONS;
    }

    public function respond(?string $message = null): string|array|DataModel|MessageInterface
    {
        $this->changeProvider($this->config['provider']);
        $this->model = $this->config['model'];

//        $cacheKey = HashService::hash(json_encode($this->getResponseSchema()).json_encode([
//            $this->provider, $this->model, $this->chunksInSchema ? 'true' : 'false'
//            ]).$this->instructions);

//        return Cache::remember($cacheKey, 300, function () {
            $response = parent::respond($this->builderStrategy->buildPrompt($this->chunksByKey));

            $keys = array_keys($this->chunksByKey);
            $range = range(0, count($this->chunksByKey) - 1);
            $indexedChunksKeys = array_combine($keys, $range);

            $responseKeys = array_keys($response);
            if (count(array_intersect($keys, $responseKeys)) !== count($responseKeys)) {
                throw new Exception("Array keys in PostProcessingAgent response do not match");
            }

            $chunksData = [];
            foreach ($response as $key => $data) {
                $chunksData[$indexedChunksKeys[$key]] = $data;
            }

            return $chunksData;
//        });
    }

    public function prompt($message)
    {
        return $message;
    }
}
