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

    protected $model = 'gpt-4.1-mini';

    protected array $index = [];
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

    protected $responseSchema = [
        'name'   => 'search_results',
        'schema' => [
            'type'       => 'object',
            'properties' => [
                'new_chapters' => [
                    'type'        => 'array',
                    'description' => 'New chapters that were not included in the definition',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'title' => [
                                'type'        => 'string',
                                'description' => 'Title of the new chapter',
                            ],
                            'chunks_uuids' => [
                                'type'        => 'array',
                                'description' => 'List of chunk UUIDs belonging to the new chapter',
                                'items'       => [
                                    'type'        => 'string',
                                    'description' => 'UUID of a single chunk',
                                ],
                            ],
                        ],
                        'required'             => ['title', 'chunks_uuids'],
                        'additionalProperties' => false,
                    ],
                ],
                'chunks_by_chapter' => [
                    'type'        => 'array',
                    'description' => 'Chunks grouped under already existing chapters',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'chapter_alias' => [
                                'type'        => 'string',
                                'description' => 'Unique alias of the existing chapter',
                            ],
                            'chunks_uuids' => [
                                'type'        => 'array',
                                'description' => 'List of chunk UUIDs belonging to the chapter',
                                'items'       => [
                                    'type'        => 'string',
                                    'description' => 'UUID of a single chunk',
                                ],
                            ],
                        ],
                        'required'             => ['chapter_uuid', 'chunks_uuids'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required'             => ['new_chapters', 'chunks_by_chapter'],
            'additionalProperties' => false,
        ],
        'strict' => true,
    ];

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

Your job is to classify document chunks into chapters.

You will receive a JSON payload containing:
- `index`: the existing chapter definitions
- `chunks`: the chunks that must be classified

Your task is to analyze the existing chapter structure and classify every provided chunk into:
- an existing chapter, or
- a genuinely new chapter when no existing chapter is a good semantic fit.
{$documentContext}
GENERAL RULES

- Classify chunks using semantic meaning, topic continuity, headings, section intent, and local context.
- Prefer assigning chunks to existing chapters when the semantic fit is strong and coherent.
- Create new chapters only when the chunk clearly does not belong to any existing chapter.
- Be conservative about creating new chapters.
- New chapters must represent genuinely distinct topics or sections, not minor variations of existing ones.
- Chunks that belong to the same missing topic must be grouped into the same new chapter.
- Do not fragment one coherent missing section into multiple new chapters.
- Do not create generic chapter titles such as "Miscellaneous", "Other", "Notes", or "Various".
- A chunk must appear only once in the final output.
- Never duplicate a chunk UUID across multiple chapters.
- Never omit a relevant chunk.
- Use only chunk UUIDs present in the input.
- Use only chapter UUIDs present in the input index.
- Never invent UUIDs.

DECISION RULES

When evaluating a chunk, consider:
- explicit headings or titles inside the chunk
- semantic similarity with existing chapters
- whether the chunk continues an already established section
- whether the chunk introduces a distinct new topic or section
- structural clues such as introductions, summaries, appendices, examples, glossaries, references, procedures, or subsections

SPECIAL OUTPUT RULES

- If no new chapters are needed, return `new_chapters` as an empty array.
- If no chunks belong to existing chapters, return `chunks_by_chapter` as an empty array.
- Group chunk UUIDs by chapter instead of emitting duplicated chapter entries unnecessarily.
- Return only schema-compliant data.
- Do not return explanations.
- Do not return markdown.
- Do not return any text outside the structured output.

INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }

    public function respond(?string $message = null): string|array|DataModel|MessageInterface
    {
        if (empty($this->index)) {
            throw new InvalidArgumentException('[IndexerAgent] Index is not defined');
        }

        if (empty($this->chunks)) {
            throw new InvalidArgumentException('[IndexerAgent] Chunks are not defined');
        }

        $payload = [
            'index'  => $this->index,
            'chunks' => $this->chunks,
        ];

        return parent::respond(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
