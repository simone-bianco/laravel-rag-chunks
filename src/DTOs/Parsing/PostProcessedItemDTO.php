<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parsing;

use Illuminate\Contracts\Support\Arrayable;

class PostProcessedItemDTO implements Arrayable
{
    public function __construct(
        public string $text,
        public array $figures,
        public ?string $textHash = null,
        public ?array $textEmbedding = null,
        public ?string $tags = null,
        public ?string $tagsHash = null,
        public ?array $tagsEmbedding = null,
        public ?string $questions = null,
        public ?string $questionsHash = null,
        public ?array $questionsEmbedding = null,
    ) {}

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'figures' => array_map(function ($figure) {
                if (is_array($figure)) {
                    return $figure;
                }
                return $figure->toArray();
            }, $this->figures),
            'text_hash' => $this->textHash,
            'text_embedding' => $this->textEmbedding,
            'tags' => $this->tags,
            'tags_hash' => $this->tagsHash,
            'tags_embedding' => $this->tagsEmbedding,
            'questions' => $this->questions,
            'questions_hash' => $this->questionsHash,
            'questions_embedding' => $this->questionsEmbedding,
        ];
    }
}
