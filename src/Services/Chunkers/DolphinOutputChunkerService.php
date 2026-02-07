<?php

namespace SimoneBianco\LaravelRagChunks\Services\Chunkers;

use Generator;
use Illuminate\Support\Str;
use JsonMachine\Exception\InvalidArgumentException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use RuntimeException;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\RefinedItemDTO;

class DolphinOutputChunkerService
{
    public function __construct(
        protected int $maxChunkSize = 500,
        protected int $generatorChunkSize = 50,
    ) {}

    public function chunkOutputJson(string $absolutePath): Generator
    {
        // 1. Apertura Stream
        $stream = fopen($absolutePath, 'r');

        if (!$stream) {
            throw new RuntimeException("Impossibile aprire il file: $absolutePath");
        }

        try {
            $elementsStream = $this->createElementsStream($stream);

            $currentText = '';
            $accumulator = [];
            $lastLabel = '';

            // Stack per tracciare la gerarchia dei titoli (es. [1 => 'Intro', 2 => 'Dettagli'])
            $headerStack = [];

            foreach ($this->yieldFlattenedElements($elementsStream) as $element) {
                $text = isset($element['text']) ? trim((string) $element['text']) : '';
                $label = $element['label'] ?? 'text';

                // --- GESTIONE CONTESTO (Titoli) ---
                // Se l'elemento è una sezione, aggiorniamo il nostro stack di contesto.
                // Cerchiamo di dedurre il livello dal label (es. 'sec_header_1' -> level 1)
                if (str_contains($label, 'sec')) {
                    $level = 1; // Default
                    if (preg_match('/(\d+)/', $label, $matches)) {
                        $level = (int)$matches[1];
                    }
                    $this->updateHeaderContext($headerStack, $level, $text);
                }

                $isFigure = str_contains($label, 'fig');

                // Skip contenuto vuoto (ma salviamo le figure perché l'url è nel markdown, non nel text puro a volte)
                if ($text === '' && !$isFigure) {
                    continue;
                }

                $isSection = str_contains($label, 'sec');
                $wasSection = str_contains($lastLabel, 'sec');

                // --- LOGICA DI FLUSH ---
                $shouldFlushText = $isFigure
                    || $label === 'tab'
                    || ($isSection && !$wasSection);

                if ($shouldFlushText && $currentText !== '') {
                    $this->addToAccumulator($accumulator, $currentText);

                    if (count($accumulator) >= $this->generatorChunkSize) {
                        yield $accumulator;
                        $accumulator = [];
                    }
                    $currentText = '';
                }

                // --- A. GESTIONE FIGURE (Granitico + Context) ---
                if ($isFigure) {
                    $imgDescription = Str::between($element['text'], '![', ']');
                    $imgPath = Str::between($element['text'], '](', ')');

                    if (!empty($imgPath)) {
                        // Se c'è una descrizione, le incolliamo il contesto prima
                        // Es: "**Context:** Capitolo 1 \n\n Descrizione immagine..."
                        $enrichedDescription = $imgDescription;
                        $contextString = $this->formatContext($headerStack);

                        if (!empty($contextString) && !empty($enrichedDescription)) {
                            $enrichedDescription = "**Context:** " . $contextString . "\n\n" . $imgDescription;
                        }

                        // Salviamo nel DTO
                        // Nota: Passiamo enrichedDescription come testo, e il path separato
                        $this->addToAccumulator($accumulator, $enrichedDescription, $imgPath);

                        if (count($accumulator) >= $this->generatorChunkSize) {
                            yield $accumulator;
                            $accumulator = [];
                        }
                    }
                    $lastLabel = $label;
                    continue;
                }

                // --- B. GESTIONE TABELLE (Granitico + Context) ---
                if ($label === 'tab') {
                    // Arricchimento contesto
                    $enrichedTable = $text;
                    $contextString = $this->formatContext($headerStack);

                    if (!empty($contextString)) {
                        $enrichedTable = "**Context:** " . $contextString . "\n\n" . $text;
                    }

                    $this->addToAccumulator($accumulator, $enrichedTable);

                    if (count($accumulator) >= $this->generatorChunkSize) {
                        yield $accumulator;
                        $accumulator = [];
                    }
                    $lastLabel = $label;
                    continue;
                }

                // --- C. GESTIONE TESTO E SEZIONI (Merging) ---
                // Il testo normale mantiene il suo flusso. I titoli (sec) vengono inclusi nel testo
                // (perché servono alla lettura), ma sono stati ANCHE usati sopra per aggiornare $headerStack.

                $separator = ($currentText === '') ? '' : "\n";
                $projectedSize = strlen($currentText) + strlen($text) + strlen($separator);

                if ($projectedSize < $this->maxChunkSize) {
                    $currentText .= $separator . $text;
                } else {
                    if ($currentText !== '') {
                        $this->addToAccumulator($accumulator, $currentText);

                        if (count($accumulator) >= $this->generatorChunkSize) {
                            yield $accumulator;
                            $accumulator = [];
                        }
                    }
                    $currentText = $text;
                }

                $lastLabel = $label;
            }

            // Cleanup finale
            if ($currentText !== '') {
                $this->addToAccumulator($accumulator, $currentText);
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

    /**
     * Aggiorna lo stack dei titoli gerarchici.
     */
    private function updateHeaderContext(array &$stack, int $level, string $text): void
    {
        $text = trim($text);
        if ($text === '') return;

        // 1. Imposta il titolo corrente per questo livello
        $stack[$level] = $text;

        // 2. Rimuovi i livelli più profondi (se passo da H1 a un nuovo H1, cancello i vecchi H2/H3)
        // Se passo da H2 a H2, cancello H3.
        foreach (array_keys($stack) as $key) {
            if ($key > $level) {
                unset($stack[$key]);
            }
        }

        ksort($stack);
    }

    /**
     * Formatta lo stack in stringa breadcrumb.
     */
    private function formatContext(array $stack): string
    {
        return implode(' | ', $stack);
    }

    protected function createElementsStream($stream): Items
    {
        return Items::fromStream($stream, [
            'pointer' => '/pages',
            'decoder' => new ExtJsonDecoder(true)
        ]);
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

    protected function addToAccumulator(array &$accumulator, string $text, ?string $figurePath = null): void
    {
        $trimmedText = trim($text);

        $accumulator[] = new RefinedItemDTO(
            text: $trimmedText,
            figurePath: $figurePath,
        );
    }
}
