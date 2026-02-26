<?php

namespace SimoneBianco\LaravelRagChunks\Services\Chunkers;

use Generator;
use Illuminate\Support\Str;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use RuntimeException;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\RefinedItemDTO;

class DolphinOutputChunkerService
{
    public function __construct(
        protected int $maxChunkSize = 800,
        protected int $generatorChunkSize = 50
    ) {
    }

    /**
     * @throws RuntimeException
     */
    public function chunkOutputJson(string $absolutePath): Generator
    {
        $stream = fopen($absolutePath, 'r');

        if (!$stream) {
            throw new RuntimeException("Impossibile aprire il file: $absolutePath");
        }

        try {
            $elementsStream = $this->createElementsStream($stream);

            $bufferText = '';
            $accumulator = [];
            $headerStack = [];

            foreach ($this->yieldFlattenedElements($elementsStream) as $element) {
                $rawText = isset($element['text']) ? (string) $element['text'] : '';
                $text = $this->cleanAndFormatText($rawText);
                $label = $element['label'] ?? 'text';

                $isFigure = str_contains($label, 'fig');
                if ($text === '' && !$isFigure) {
                    continue;
                }

                $isSection = str_contains($label, 'sec');
                if ($isSection) {
                    $level = 1;
                    if (preg_match('/(\d+)/', $label, $matches)) {
                        $level = (int)$matches[1];
                    }
                    $this->updateHeaderContext($headerStack, $level, $text);
                }

                if ($isFigure) {
                    if ($bufferText !== '') {
                        $accumulator[] = new RefinedItemDTO(text: trim($bufferText), figurePath: null);
                        $bufferText = '';
                    }

                    $this->handleFigure($accumulator, $rawText, $headerStack);

                    if (count($accumulator) >= $this->generatorChunkSize) {
                        yield $accumulator;
                        $accumulator = [];
                    }
                    continue;
                }

                if ($label === 'tab') {
                    $context = $this->formatContext($headerStack);
                    if ($context) {
                        $text = '**Context:** ' . Str::limit($context, 80) . "\n\n" . $text;
                    }
                }

                $separator = $bufferText === '' ? '' : "\n\n";

                if ($bufferText !== '' && strlen($bufferText) + strlen($separator) + strlen($text) > $this->maxChunkSize) {
                    $accumulator[] = new RefinedItemDTO(text: trim($bufferText), figurePath: null);
                    $bufferText = '';
                    $separator = '';

                    if (count($accumulator) >= $this->generatorChunkSize) {
                        yield $accumulator;
                        $accumulator = [];
                    }
                }

                $bufferText .= $separator . $text;

                while (strlen($bufferText) > $this->maxChunkSize) {
                    $cutAt = strrpos(substr($bufferText, 0, $this->maxChunkSize), ' ');
                    if ($cutAt === false || $cutAt < $this->maxChunkSize * 0.8) {
                        $cutAt = $this->maxChunkSize;
                    }

                    $chunkToSave = substr($bufferText, 0, $cutAt);
                    $accumulator[] = new RefinedItemDTO(text: trim($chunkToSave), figurePath: null);
                    $bufferText = trim(substr($bufferText, $cutAt));

                    if (count($accumulator) >= $this->generatorChunkSize) {
                        yield $accumulator;
                        $accumulator = [];
                    }
                }
            }

            if (trim($bufferText) !== '') {
                $accumulator[] = new RefinedItemDTO(text: trim($bufferText), figurePath: null);
            }

            if (!empty($accumulator)) {
                yield $accumulator;
            }

        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function cleanAndFormatText(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }

        if (!str_contains($text, '<')) {
            return trim($text);
        }

        $text = str_ireplace(['</td>', '</th>'], ' | ', $text);
        $text = str_ireplace(['</tr>', '<br>', '<br/>'], "\n", $text);
        $text = strip_tags($text);

        $lines = explode("\n", $text);
        $cleanLines = [];

        foreach ($lines as $line) {
            $t = trim($line);
            if ($t !== '') {
                $t = preg_replace('/\s+/', ' ', $t);
                $cleanLines[] = rtrim($t, '| ');
            }
        }

        return implode("\n", $cleanLines);
    }

    private function handleFigure(array &$accumulator, string $rawText, array $headerStack): void
    {
        $desc = Str::between($rawText, '![', ']');
        $path = Str::between($rawText, '](', ')');

        if (!$path) {
            return;
        }

        $cleanDesc = $this->cleanAndFormatText($desc);
        $context = $this->formatContext($headerStack);

        $final = $cleanDesc ?: "Image output";

        if ($context) {
            $final = "**Context:** $context\n\n" . $final;
        }

        $accumulator[] = new RefinedItemDTO(text: $final, figurePath: $path);
    }

    private function updateHeaderContext(array &$stack, int $level, string $text): void
    {
        $text = trim($text);
        if (!$text) {
            return;
        }

        $stack[$level] = $text;

        foreach (array_keys($stack) as $k) {
            if ($k > $level) {
                unset($stack[$k]);
            }
        }
        ksort($stack);
    }

    private function formatContext(array $stack): string
    {
        return implode(' | ', $stack);
    }

    protected function createElementsStream($stream): Items
    {
        return Items::fromStream(
            $stream,
            ['pointer' => '/pages', 'decoder' => new ExtJsonDecoder(true)]
        );
    }

    protected function yieldFlattenedElements(Items $pages): Generator
    {
        foreach ($pages as $p) {
            foreach ($p['elements'] ?? [] as $e) {
                yield $e;
            }
        }
    }
}
