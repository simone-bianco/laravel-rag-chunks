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
        protected int $targetChunkSize = 500,     // Sweet spot
        protected int $minChunkSize = 300,        // Minimo assoluto
        protected int $maxChunkSize = 1000,       // Massimo assoluto (Hard Limit)
        protected int $generatorChunkSize = 50    // Numero di elementi per yield
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
                // 1. Pulizia & Estrazione
                $rawText = isset($element['text']) ? (string) $element['text'] : '';
                $text = $this->cleanAndFormatText($rawText);
                $label = $element['label'] ?? 'text';

                // Skip vuoto (tranne figure)
                $isFigure = str_contains($label, 'fig');
                if ($text === '' && !$isFigure) {
                    continue;
                }

                // Gestione Contesto Headers (non triggera flush, serve solo per arricchire)
                $isSection = str_contains($label, 'sec');
                if ($isSection) {
                    $level = 1;
                    if (preg_match('/(\d+)/', $label, $matches)) {
                        $level = (int)$matches[1];
                    }
                    $this->updateHeaderContext($headerStack, $level, $text);
                }

                // --- GESTIONE SPECIALE: FIGURE ---
                if ($isFigure) {
                    // Le figure sono oggetti a sé stanti. Se abbiamo testo nel buffer,
                    // dobbiamo decidere cosa farne.
                    if ($bufferText !== '') {
                        // Se è troppo piccolo (<300), le figure purtroppo rompono l'accumulo.
                        // Ma per non perdere dati, lo salviamo comunque.
                        $this->addChunk($accumulator, $bufferText);
                        $bufferText = '';
                    }

                    $this->handleFigure($accumulator, $rawText, $headerStack);

                    // Controllo yield dopo inserimento figura
                    if (count($accumulator) >= $this->generatorChunkSize) {
                        yield $accumulator;
                        $accumulator = [];
                    }
                    continue;
                }

                // --- COSTRUZIONE BUFFER ---

                // Determina separatore
                $separator = " "; // Default: unisci con spazio
                if ($bufferText !== '') {
                    $lastChar = substr(trim($bufferText), -1);
                    // Se finisce con punto, due punti, pipe (tabella) o titolo -> Newline
                    if (in_array($lastChar, ['.', '!', '?', ':', '|'])) {
                        $separator = "\n\n";
                    }
                    // Se l'elemento corrente è una tabella -> Newline prima
                    if ($label === 'tab') {
                        $separator = "\n\n";
                    }
                }

                // Arricchisci tabelle con contesto
                if ($label === 'tab') {
                    $context = $this->formatContext($headerStack);
                    if ($context) {
                        $text = '**Context:** ' . Str::limit($context, 80) . "\n\n" . $text;
                    }
                }

                // Aggiungi al buffer
                $bufferText .= ($bufferText === '' ? '' : $separator) . $text;

                // --- LOGICA DI TAGLIO (CORE) ---

                $currentLen = strlen($bufferText);

                // 1. Se siamo sotto il minimo, CONTINUA e basta.
                if ($currentLen < $this->minChunkSize) {
                    continue;
                }

                // 2. Se siamo sopra il massimo, TAGLIO FORZATO.
                if ($currentLen >= $this->maxChunkSize) {
                    $this->processLargeBuffer($accumulator, $bufferText);
                    $bufferText = '';

                    if (count($accumulator) >= $this->generatorChunkSize) {
                        yield $accumulator;
                        $accumulator = [];
                    }
                    continue;
                }

                // 3. Se siamo nello "Sweet Spot", cerchiamo un buon punto di rottura.
                if ($currentLen >= $this->targetChunkSize) {
                    // Se l'elemento corrente era una sezione o una tabella, o finiva con un punto,
                    // è un ottimo momento per flushare e creare un chunk pulito.
                    $isGoodBreakPoint = $isSection
                        || $label === 'tab'
                        || preg_match('/[.?!:|]$/', trim($text));

                    if ($isGoodBreakPoint) {
                        $this->addChunk($accumulator, $bufferText);
                        $bufferText = '';

                        if (count($accumulator) >= $this->generatorChunkSize) {
                            yield $accumulator;
                            $accumulator = [];
                        }
                    }
                }
            }

            // Flush finale del testo rimasto nel buffer
            if ($bufferText !== '') {
                // Se è rimasto un rimasuglio piccolo, proviamo ad attaccarlo all'ultimo
                if (strlen($bufferText) < $this->minChunkSize && !empty($accumulator)) {
                    $this->mergeWithLast($accumulator, $bufferText);
                } else {
                    $this->processLargeBuffer($accumulator, $bufferText);
                }
            }

            // Yield finale di ciò che è rimasto nell'accumulatore
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
     * Gestisce un buffer che potrebbe essere > 1000 caratteri.
     * Lo spezza forzatamente in chunk validi (300-1000).
     */
    private function processLargeBuffer(array &$accumulator, string $text): void
    {
        // Se è nei limiti, salva e via
        if (strlen($text) <= $this->maxChunkSize) {
            $this->addChunk($accumulator, $text);
            return;
        }

        // Se è gigante, spezza
        $chunks = $this->splitTextConstraints($text);
        foreach ($chunks as $chunk) {
            $this->addChunk($accumulator, $chunk);
        }
    }

    /**
     * Algoritmo di split che rispetta rigorosamente Min e Max size.
     */
    private function splitTextConstraints(string $text): array
    {
        $chunks = [];
        // Proviamo a spezzare prima per newlines (paragrafi)
        $blocks = explode("\n\n", $text);

        $currentChunk = '';

        foreach ($blocks as $block) {
            // Se il blocco singolo è già enorme (> Max), dobbiamo spezzarlo col wordwrap
            if (strlen($block) > $this->maxChunkSize) {
                // Flusha quello che c'era prima
                if ($currentChunk !== '') {
                    $chunks[] = $currentChunk;
                    $currentChunk = '';
                }

                // Taglio brutale ma sicuro a blocchi di targetChunkSize
                $subChunks = explode("\n", wordwrap($block, $this->targetChunkSize, "\n", true));

                // Riaggreghiamo i subchunks per rispettare il minSize
                $subBuffer = '';
                foreach ($subChunks as $sub) {
                    if (strlen($subBuffer) + strlen($sub) > $this->maxChunkSize) {
                        $chunks[] = $subBuffer;
                        $subBuffer = $sub;
                    } else {
                        $subBuffer .= ($subBuffer ? "\n" : '') . $sub;
                    }
                }
                if ($subBuffer) {
                    // Il rimasuglio lo trattiamo come currentChunk per il prossimo giro
                    $currentChunk = $subBuffer;
                }
                continue;
            }

            // Logica normale di accumulo
            $sep = ($currentChunk === '') ? '' : "\n\n";
            if (strlen($currentChunk) + strlen($sep) + strlen($block) > $this->maxChunkSize) {
                // Se aggiungendo questo blocco superiamo il MAX, flushiamo il corrente
                if ($currentChunk !== '') {
                    $chunks[] = $currentChunk;
                }
                $currentChunk = $block;
            } else {
                // Altrimenti accumula
                $currentChunk .= $sep . $block;
            }
        }

        if ($currentChunk !== '') {
            $chunks[] = $currentChunk;
        }

        // Refine pass: Se ci sono chunk < MinSize, uniscili
        return $this->refineChunks($chunks);
    }

    private function refineChunks(array $chunks): array
    {
        if (count($chunks) < 2) {
            return $chunks;
        }

        $refined = [];
        $buffer = array_shift($chunks);

        foreach ($chunks as $chunk) {
            if (strlen($buffer) < $this->minChunkSize) {
                $buffer .= "\n\n" . $chunk;
            } else {
                $refined[] = $buffer;
                $buffer = $chunk;
            }
        }

        // Controllo finale sull'ultimo pezzo
        if (strlen($buffer) < $this->minChunkSize && !empty($refined)) {
            $lastIdx = count($refined) - 1;
            $refined[$lastIdx] .= "\n\n" . $buffer;
        } else {
            $refined[] = $buffer;
        }

        return $refined;
    }

    private function addChunk(array &$accumulator, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $accumulator[] = new RefinedItemDTO(text: $text, figurePath: null);
    }

    private function mergeWithLast(array &$accumulator, string $text): void
    {
        $lastIdx = count($accumulator) - 1;

        if ($lastIdx >= 0 && $accumulator[$lastIdx]->figurePath === null) {
            $prev = $accumulator[$lastIdx]->text;
            $accumulator[$lastIdx] = new RefinedItemDTO(
                text: $prev . "\n\n" . trim($text),
                figurePath: null
            );
        } else {
            $this->addChunk($accumulator, $text);
        }
    }

    // --- CLEANERS & HELPERS ---

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

        // Rimuove i livelli più profondi se si torna a un livello superiore
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
