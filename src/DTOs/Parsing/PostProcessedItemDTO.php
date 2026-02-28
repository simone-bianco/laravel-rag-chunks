<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parsing;

use Illuminate\Contracts\Support\Arrayable;

class PostProcessedItemDTO implements Arrayable
{
    public function __construct(
        public string $text,
        public ?string $figurePath = null,
        public ?string $textHash = null,
        public ?array $textEmbedding = null,
        public ?array $tags = null,
        public ?string $tagsHash = null,
        public ?array $tagsEmbedding = null,
        public ?array $questions = null,
        public ?string $questionsHash = null,
        public ?array $questionsEmbedding = null,
        public ?array $deterministicTags = null,
    ) {}

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'figure_path' => $this->figurePath,
            'text_hash' => $this->textHash,
            'text_embedding' => $this->textEmbedding,
            'tags' => $this->tags,
            'tags_hash' => $this->tagsHash,
            'tags_embedding' => $this->tagsEmbedding,
            'questions' => $this->questions,
            'questions_hash' => $this->questionsHash,
            'questions_embedding' => $this->questionsEmbedding,
            'deterministic_tags' => $this->deterministicTags,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            text: $data['text'],
            figurePath: $data['figure_path'],
            textHash: $data['text_hash'],
            textEmbedding: $data['text_embedding'],
            tags: $data['tags'],
            tagsHash: $data['tags_hash'],
            tagsEmbedding: $data['tags_embedding'],
            questions: $data['questions'],
            questionsHash: $data['questions_hash'],
            questionsEmbedding: $data['questions_embedding'],
            deterministicTags: $data['deterministic_tags'] ?? null,
        );
    }
}
