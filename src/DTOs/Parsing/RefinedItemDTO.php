<?php

namespace SimoneBianco\LaravelRagChunks\DTOs\Parsing;

use Illuminate\Contracts\Support\Arrayable;

class RefinedItemDTO implements Arrayable
{
    /**
     * @param string $text
     * @param array<FigureDTO> $figures
     */
    public function __construct(
        public string $text,
        public array $figures,
    ) {}

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'figures' => array_map(function (FigureDTO $figure) {
                return $figure->toArray();
            }, $this->figures),
        ];
    }

    public static function fromArray(array $data): static
    {
        $data['figures'] = array_map(function ($figure) {
            return FigureDTO::fromArray($figure);
        }, $data['figures']);

        return new static(...$data);
    }
}
