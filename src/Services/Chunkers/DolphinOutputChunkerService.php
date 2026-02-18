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
        protected int $maxChunkSize = 500,
        protected int $generatorChunkSize = 50,
        protected int $hardSplitThreshold = 1000,
        protected int $minChunkTolerance = 50
    ) {}

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

                // Skip vuoto (eccetto figure)
                $isFigure = str_contains($label, 'fig');
                if ($text === '' && !$isFigure) {
                    continue;
                }

                // Header Context
                $isSection = str_contains($label, 'sec');
                if ($isSection) {
                    $level = 1;
                    if (preg_match('/(\d+)/', $label, $matches)) {
                        $level = (int)$matches[1];
                    }
                    $this->updateHeaderContext($headerStack, $level, $text);
                }

                // --- LOGICA DI FLUSSO ---

                // A. FIGURE
                if ($isFigure) {
                    if ($bufferText !== '') {
                        $this->addToAccumulator($accumulator, $bufferText);
                        $bufferText = '';
                        if (count($accumulator) >= $this->generatorChunkSize) {
                            yield $accumulator;
                            $accumulator = [];
                        }
                    }
                    $this->handleFigure($accumulator, $rawText, $headerStack);
                    if (count($accumulator) >= $this->generatorChunkSize) {
                        yield $accumulator;
                        $accumulator = [];
                    }
                    continue;
                }

                // B. SEZIONI (FIX APPLICATA QUI)
                if ($isSection) {
                    // Se arriva un nuovo titolo, flushiamo il buffer SOLO SE il buffer
                    // contiene abbastanza testo. Se contiene solo un altro titoletto ("DEVA"),
                    // lo teniamo in canna per unirlo a questo.
                    if ($bufferText !== '' && strlen($bufferText) >= $this->minChunkTolerance) {
                        $this->addToAccumulator($accumulator, $bufferText);
                        $bufferText = '';
                        if (count($accumulator) >= $this->generatorChunkSize) {
                            yield $accumulator;
                            $accumulator = [];
                        }
                    }
                    // Se il buffer era < 50 char (es. "DEVA"), non facciamo nulla.
                    // Il codice andrà avanti e appenderà il nuovo titolo ("Medium celestial...")
                    // al buffer esistente ("DEVA").
                }

                // Prepara il contenuto
                $contentToAdd = $text;
                if ($label === 'tab') {
                    $contextString = $this->formatContext($headerStack);
                    if (!empty($contextString)) {
                        $contentToAdd = "**Context:** " . $contextString . "\n\n" . $text;
                    }
                }

                // C. HARD SPLIT
                if (strlen($contentToAdd) > $this->hardSplitThreshold) {
                    $combinedText = ($bufferText === '') ? $contentToAdd : $bufferText . "\n\n" . $contentToAdd;
                    $bufferText = '';

                    $chunks = $this->splitLargeText($combinedText, $this->maxChunkSize);
                    foreach ($chunks as $chunk) {
                        $this->addToAccumulator($accumulator, $chunk);
                    }

                    if (count($accumulator) >= $this->generatorChunkSize) {
                        yield $accumulator;
                        $accumulator = [];
                    }
                    continue;
                }

                // D. MERGE NORMALE
                $separator = ($bufferText === '') ? '' : "\n\n";
                $projectedSize = strlen($bufferText) + strlen($separator) + strlen($contentToAdd);

                if ($projectedSize <= $this->maxChunkSize) {
                    $bufferText .= $separator . $contentToAdd;
                } else {
                    if ($bufferText !== '') {
                        $this->addToAccumulator($accumulator, $bufferText);
                        if (count($accumulator) >= $this->generatorChunkSize) {
                            yield $accumulator;
                            $accumulator = [];
                        }
                    }
                    $bufferText = $contentToAdd;
                }
            }

            if ($bufferText !== '') {
                $this->addToAccumulator($accumulator, $bufferText);
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

    // --- HELPER METHODS (Invariati) ---
    private function cleanAndFormatText(string $text): string
    {
        if (trim($text) === '') return '';
        if (!str_contains($text, '<')) return trim($text);

        $text = str_ireplace(['</td>', '</th>'], ' | ', $text);
        $text = str_ireplace(['</tr>', '<br>', '<br/>', '<br />'], "\n", $text);
        $text = strip_tags($text);

        $lines = explode("\n", $text);
        $cleanLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $cleaned = preg_replace('/\s+/', ' ', $trimmed);
                $cleaned = rtrim($cleaned, '| ');
                $cleanLines[] = $cleaned;
            }
        }
        return implode("\n", $cleanLines);
    }

    protected function addToAccumulator(array &$accumulator, string $text, ?string $figurePath = null): void
    {
        $trimmedText = trim($text);
        if ($trimmedText === '' && $figurePath === null) return;

        if ($figurePath !== null) {
            $accumulator[] = new RefinedItemDTO(text: $trimmedText, figurePath: $figurePath);
            return;
        }

        if (strlen($trimmedText) < $this->minChunkTolerance && !empty($accumulator)) {
            $lastIndex = count($accumulator) - 1;
            $lastItem = $accumulator[$lastIndex];
            if ($lastItem->figurePath === null) {
                $newText = $lastItem->text . "\n\n" . $trimmedText;
                $accumulator[$lastIndex] = new RefinedItemDTO(text: $newText, figurePath: null);
                return;
            }
        }

        $accumulator[] = new RefinedItemDTO(text: $trimmedText, figurePath: null);
    }

    private function handleFigure(array &$accumulator, string $rawText, array $headerStack): void
    {
        $imgDescription = Str::between($rawText, '![', ']');
        $imgPath = Str::between($rawText, '](', ')');
        if (empty($imgPath)) return;

        $cleanDescription = $this->cleanAndFormatText($imgDescription);
        $enrichedDescription = $cleanDescription;
        $contextString = $this->formatContext($headerStack);

        if (!empty($contextString)) {
            $descToUse = !empty($cleanDescription) ? $cleanDescription : "Image output";
            $enrichedDescription = "**Context:** " . $contextString . "\n\n" . $descToUse;
        }
        $this->addToAccumulator($accumulator, $enrichedDescription, $imgPath);
    }

    private function splitLargeText(string $text, int $chunkSize): array
    {
        $lines = explode("\n", $text);
        $chunks = [];
        $currentChunk = '';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            if (strlen($line) > $chunkSize) {
                $fullText = ($currentChunk === '' ? '' : $currentChunk . "\n\n") . $line;
                $currentChunk = '';
                $subChunks = explode("\n", wordwrap($fullText, $chunkSize, "\n", true));
                foreach ($subChunks as $sub) {
                    $sub = trim($sub);
                    if ($sub !== '') $chunks[] = $sub;
                }
            } else {
                if (strlen($currentChunk) + strlen($line) + 2 > $chunkSize) {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = $line;
                } else {
                    $currentChunk .= ($currentChunk === '' ? '' : "\n\n") . $line;
                }
            }
        }
        if ($currentChunk !== '') $chunks[] = trim($currentChunk);
        return $this->refineSplitChunks($chunks);
    }

    private function refineSplitChunks(array $chunks): array
    {
        if (count($chunks) < 2) return $chunks;
        $refined = [];
        $buffer = array_shift($chunks);
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < $this->minChunkTolerance) {
                $buffer .= "\n" . $chunk;
            } else {
                $refined[] = $buffer;
                $buffer = $chunk;
            }
        }
        $refined[] = $buffer;
        return $refined;
    }

    private function updateHeaderContext(array &$stack, int $level, string $text): void
    {
        $text = trim($text);
        if ($text === '') return;
        $stack[$level] = $text;
        foreach (array_keys($stack) as $key) {
            if ($key > $level) unset($stack[$key]);
        }
        ksort($stack);
    }

    private function formatContext(array $stack): string
    {
        return implode(' | ', $stack);
    }

    protected function createElementsStream($stream): Items
    {
        return Items::fromStream($stream, ['pointer' => '/pages', 'decoder' => new ExtJsonDecoder(true)]);
    }

    protected function yieldFlattenedElements(Items $pages): Generator
    {
        foreach ($pages as $page) {
            $elements = $page['elements'] ?? [];
            foreach ($elements as $element) {
                yield $element;
            }
        }
    }
}
