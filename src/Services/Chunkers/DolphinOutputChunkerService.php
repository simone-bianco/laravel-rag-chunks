<?php

namespace SimoneBianco\LaravelRagChunks\Services\Chunkers;

use Generator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonMachine\Exception\InvalidArgumentException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\RefinedItemDTO;

class DolphinOutputChunkerService
{
    public function __construct(
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

    public function chunkOutputJson(string $outputJsonRelativePath): Generator
    {
        $elementsStream = $this->createElementsStream($outputJsonRelativePath);

        $currentText = '';
        $accumulator = [];
        $lastLabel = ''; // Variabile per tracciare il tipo dell'elemento precedente
        foreach ($this->yieldFlattenedElements($elementsStream) as $element) {
            $text = isset($element['text']) ? trim((string) $element['text']) : '';
            $label = $element['label'] ?? 'text';

            $isFigure = str_contains($label, 'fig');
            // Check testo vuoto (mantenendo le figure)
            if ($text === '' && !$isFigure) {
                continue;
            }

            $isSection = str_contains($label, 'sec');
            $wasSection = str_contains($lastLabel, 'sec');

            // --- LOGICA DI FLUSH MODIFICATA ---
            // 1. Figure: Flush sempre.
            // 2. Tabelle: Flush sempre.
            // 3. Sezioni: Flush SOLO SE quello prima NON era una sezione.
            //    (Se ho sec_1 seguito da sec_2, li voglio uniti nello stesso blocco di testo)
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

            // A. Gestione Figure
            if ($isFigure) {
                $imgDescription = Str::between($element['text'], '![', ']');
                $imgPath = Str::between($element['text'], '](', ')');

                if (!empty($imgPath) && !empty($imgDescription)) {
                    $this->addToAccumulator($accumulator, $imgDescription, $imgPath);

                    if (count($accumulator) >= $this->generatorChunkSize) {
                        yield $accumulator;
                        $accumulator = [];
                    }
                }
                $lastLabel = $label; // Aggiorno lastLabel
                continue;
            }

            // B. Gestione Tabelle
            if ($label === 'tab') {
                $this->addToAccumulator($accumulator, $text);

                if (count($accumulator) >= $this->generatorChunkSize) {
                    yield $accumulator;
                    $accumulator = [];
                }
                $lastLabel = $label; // Aggiorno lastLabel
                continue;
            }

            // C. Gestione Testo e Sezioni (Merging)
            // Se siamo qui, significa che NON abbiamo flushato, oppure abbiamo flushato ed è vuoto.
            // In caso di sezioni consecutive ($isSection && $wasSection), il flush non è scattato,
            // quindi il codice qui sotto accoderà il nuovo titolo a quello vecchio.

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

            // Importante: Aggiornare lastLabel alla fine del ciclo
            $lastLabel = $label;
        }

        // Cleanup finale (identico a prima, garantisce nessun dato perso)
        if ($currentText !== '') {
            $this->addToAccumulator($accumulator, $currentText);
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
     * @param array $accumulator
     * @param string $text
     * @param string|null $figurePath
     * @return void
     */
    protected function addToAccumulator(array &$accumulator, string $text, ?string $figurePath = null): void
    {
        $trimmedText = trim($text);

        $accumulator[] = new RefinedItemDTO(
            text: $trimmedText,
            figurePath: $figurePath,
        );
    }
}
