<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parsing;

use Illuminate\Contracts\Support\Arrayable;

class PostProcessedItemDTO implements Arrayable
{
    /**
     * @param string $text
     * @param FigureDTO[] $figures
     * @param string $hash
     * @param string|null $textEmbedding
     * @param string|null $tags
     * @param array|null $tagsEmbedding
     * @param string|null $questions
     * @param array|null $questionsEmbedding
     */
    public function __construct(
        public string $text,
        public array $figures,
        public string $hash,
        public ?string $textEmbedding = null,
        public ?string $tags = null,
        public ?array $tagsEmbedding = null,
        public ?string $questions = null,
        public ?array $questionsEmbedding = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'text' => $this->text,
            'figures' => array_map(function (FigureDTO $figure) {
                return $figure->toArray();
            }, $this->figures),
            'hash' => $this->hash,
            'text_embedding' => $this->textEmbedding,
            'tags' => $this->tags,
            'tags_embedding' => $this->tagsEmbedding,
            'questions' => $this->questions,
            'questions_embedding' => $this->questionsEmbedding,
        ]);
    }
}
