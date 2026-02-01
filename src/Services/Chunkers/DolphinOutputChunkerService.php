<?php

namespace SimoneBianco\LaravelRagChunks\Services\Chunkers;

use Generator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonMachine\Exception\InvalidArgumentException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\FigureDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\RefinedItemDTO;
use SimoneBianco\LaravelRagChunks\Services\HashService;
use Throwable;

class DolphinOutputChunkerService
{
    public function __construct(
        protected HashService $hashService,
        protected ?Filesystem $storage = null,
        protected int $maxChunkSize = 500,
        protected int $generatorChunkSize = 50,
    ) {
        $this->storage ??= Storage::disk('local');
    }

    protected function storage(): Filesystem
    {
        return $this->storage;
    }

    /**
     * @param string $outputJsonRelativePath
     * @return Generator
     * @throws InvalidArgumentException
     */
    public function chunkOutputJson(string $outputJsonRelativePath): Generator
    {
        $elementsStream = $this->createElementsStream($outputJsonRelativePath);

        $accumulator = [];
        $currentText = '';
        $currentFigures = [];
        $pendingFigures = [];
        foreach ($this->yieldFlattenedElements($elementsStream) as $element) {
            // A. Gestione Figure
            if (str_contains($element['label'], 'fig')) {
                $imgDescription = Str::between($element['text'], '![', ']');
                $imgPath = Str::between($element['text'], '](', ')');
                if (!empty($imgPath)) {
                    $pendingFigures[] = new FigureDTO(
                        path: $imgPath,
                        description: $imgDescription
                    );
                }
                continue;
            }

            // B. Gestione Testo
            $text = isset($element['text']) ? trim((string) $element['text']) : '';
            if ($text === '') {
                continue;
            }

            // C. Logica di Split
            $separator = ($currentText === '') ? '' : "\n";
            $projectedSize = strlen($currentText) + strlen($text) + strlen($separator);
            if ($projectedSize < $this->maxChunkSize) {
                $currentText .= $separator . $text;
            } else {
                if ($currentText !== '') {
                    $this->addToAccumulator($accumulator, $currentText, $currentFigures);

                    if (count($accumulator) >= $this->generatorChunkSize) {
                        yield $accumulator;
                        $accumulator = [];
                    }
                }

                $currentText = $text;
                $currentFigures = [];
            }
            $this->mergePendingFigures($currentFigures, $pendingFigures);
        }

        $this->mergePendingFigures($currentFigures, $pendingFigures);

        if ($currentText !== '' || !empty($currentFigures)) {
            $this->addToAccumulator($accumulator, $currentText, $currentFigures);
        }

        if (!empty($accumulator)) {
            yield $accumulator;
        }
    }

    /**
     * @param string $relativePath
     * @return Items
     * @throws InvalidArgumentException
     */
    protected function createElementsStream(string $relativePath): Items
    {
        $stream = $this->storage()->readStream($relativePath);

        return Items::fromStream($stream, [
            'pointer' => '/pages',
            'decoder' => new ExtJsonDecoder(true)
        ]);
    }

    /**
     * @param Items $pages
     * @return Generator
     */
    protected function yieldFlattenedElements(Items $pages): Generator
    {
        foreach ($pages as $page) {
            $elements = $page['elements'] ?? [];
            foreach ($elements as $element) {
                yield $element;
            }
        }
    }

    /**
     * @param array $currentFigures
     * @param array $pendingFigures
     * @return void
     */
    protected function mergePendingFigures(array &$currentFigures, array &$pendingFigures): void
    {
        if (!empty($pendingFigures)) {
            $currentFigures = array_merge($currentFigures, $pendingFigures);
            $pendingFigures = [];
        }
    }

    /**
     * @param array $accumulator
     * @param string $text
     * @param array $figures
     * @return void
     */
    protected function addToAccumulator(array &$accumulator, string $text, array $figures): void
    {
        $trimmedText = trim($text);

        $accumulator[] = new RefinedItemDTO(
            text: $trimmedText,
            figures: $figures,
            hash: $this->hashService->hash($trimmedText)
        );
    }
}
