<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\Models\Document;

class IndexerAgent extends RotableAgent
{
    protected $history = 'in_memory';

//    protected $provider = 'ollama';
//    protected $model = 'gemma3:12b';

    protected $model = 'gpt-5-mini';

    protected ?array $index = null;
    protected array $chunks = [];
    protected string $documentInstructions = '';

    public function withDocument(Document $document): self
    {
        $name = trim((string) $document->name);
        $description = trim((string) ($document->description ?? ''));

        $this->documentInstructions = trim(
            "Reference document context:\n"
            . "- Name: {$name}\n"
            . "- Description: {$description}"
        );

        return $this;
    }

    public function withIndex(array $index): self
    {
        $this->index = $index;

        return $this;
    }

    public function withChunks(array $chunks): self
    {
        $this->chunks = $chunks;

        return $this;
    }

    public function structuredOutput(): array
    {
        return $this->getResponseSchema();
    }

    protected function getResponseSchema(): array
    {
        if (empty($this->chunks)) {
            throw new InvalidArgumentException('[IndexerAgent] Chunks are not defined');
        }

        $chunksProperties = [];
        foreach ($this->chunks as $chunk) {
            $chunksProperties["chunk_{$chunk['uuid']}"] = [
                'type' => 'object',
                'description' => $chunk['content'],
                'properties' => [
                    'chapter_title' => [
                        'type' => 'string',
                        'description' => 'Title of the chapter',
                    ],
                ],
                'required' => ['chapter_title'],
                'additional_properties' => false
            ];
        }

        return [
            'type'       => 'object',
            'properties' => [
                'chunks_with_chapter' => [
                    'type'        => 'object',
                    'description' => 'All the chunks, each with an assigned chapter; multiple chunks CAN HAVE the same chapter!',
                    'properties' => $chunksProperties,
                    'required'             => array_keys($chunksProperties),
                ],
            ],
            'required'             => ['chunks_with_chapter'],
            'additionalProperties' => false,
        ];
    }

    protected function logger(): LoggerInterface
    {
        return Log::channel('index');
    }

    public function __construct(
        $key,
        bool $usesUserId = false,
        ?string $group = null
    ) {
        parent::__construct($key, $usesUserId, $group);

        $this->logger()->debug('[Agent] IndexerAgent initialized');
    }

    public function instructions(): string
    {
        $documentContext = $this->documentInstructions !== ''
            ? "\nDOCUMENT CONTEXT\n\n{$this->documentInstructions}\n"
            : '';

        return <<<INSTRUCTIONS
You are an indexing agent.

Assign one `chapter_title` to every provided chunk.

You will receive:
- the current index
- a structured output schema that already defines all chunks to classify

{$documentContext}
RULES

- The goal is clustering, not summarizing each chunk.
- Multiple chunks should share the exact same chapter title when they belong to the same broader section.
- Prefer reusing the same chapter title whenever the fit is reasonable.
- Minimize the number of distinct chapter titles.
- Do not create a new title just because a chunk is more specific, uses different wording, or covers a sub-point of the same topic.
- Create a different title only when using the same one would be clearly wrong.
- Prefer broader but still accurate chapter titles over narrow per-chunk titles.
- Use the existing index for naming consistency when helpful.
- Keep titles short, clear, specific, and section-level.
- Do not use vague titles like "Miscellaneous", "Other", "Notes", or "General".
- Do not include the document name in the title.
- If there is an index, you can use it to get a starting point for the arguments that the document will contain

OUTPUT RULES

- Assign exactly one `chapter_title` to every chunk in the schema.
- Do not omit any chunk.
- Do not add extra properties or extra chunks.
- Return only schema-compliant structured output.
- No explanations.
- No markdown.
- No text outside the structured output.

Each `chunk_<uuid>` field in the schema contains the chunk content in its description. Use that content to determine the best shared chapter title.

INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }

    public function respond(?string $message = null): string|array|DataModel|MessageInterface
    {
        if ($this->index === null) {
            throw new InvalidArgumentException('[IndexerAgent] Index is not defined');
        }

        $payload = [
            'current_index'  => $this->index,
        ];

        return parent::respond(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
