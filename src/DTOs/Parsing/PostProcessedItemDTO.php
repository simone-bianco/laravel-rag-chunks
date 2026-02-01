<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parsing;

use Illuminate\Contracts\Support\Arrayable;

class PostProcessedItemDTO implements Arrayable
{
    /**
     * @param string $text
     * @param array $figures
     * @param string $hash
     * @param string|null $tags
     * @param string|null $questions
     */
    public function __construct(
        public string $text,
        public array $figures,
        public string $hash,
        public ?string $tags = null,
        public ?string $questions = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'text' => $this->text,
            'figures' => array_map(function (FigureDTO $figure) {
                return $figure->toArray();
            }, $this->figures),
            'hash' => $this->hash,
            'tags' => $this->tags,
            'questions' => $this->questions,
        ]);
    }
}
