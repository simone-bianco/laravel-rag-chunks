<?php

namespace SimoneBianco\LaravelRagChunks\Services\Chunkers;

use Generator;
use RuntimeException;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\RefinedItemDTO;

class MarkdownChunkerService
{
    private const string TYPE_TEXT = 'text';
    private const string TYPE_MERMAID = 'mermaid'; // Granitico
    private const string TYPE_TABLE = 'table';     // Granitico
    private const string TYPE_CODE = 'code';       // Granitico o Testo (qui trattato come granitico per sicurezza)

    public function __construct(
        protected int $maxChunkSize = 1000, // Aumentato leggermente per accomodare il contesto iniettato
        protected int $generatorChunkSize = 50,
    ) {
    }

    /**
     * Entry point. Prende il path assoluto, apre lo stream e orchestra il tutto.
     */
    public function chunkMarkdown(string $absolutePath): Generator
    {
        // 1. Apertura Stream Locale
        $stream = @fopen($absolutePath, 'r');

        if ($stream === false) {
            throw new RuntimeException("Impossibile aprire il file: $absolutePath");
        }

        try {
            $accumulator = [];
            $currentTextBuffer = '';

            // 2. Iteriamo sui blocchi logici (che ora contengono anche il "context")
            foreach ($this->yieldBlocksFromStream($stream) as $block) {

                $type = $block['type'];
                $rawContent = $block['content'];
                $contextString = $block['context']; // Es: "Titolo Principale | Sottosezione"

                // --- CASO A: Blocchi GRANITICI (Tabelle e Mermaid) ---
                // Questi devono essere isolati per non spezzare la sintassi.
                // Inoltre, essendo isolati, perdono il contesto del testo attorno, quindi lo iniettiamo.
                if ($type === self::TYPE_MERMAID || $type === self::TYPE_TABLE) {

                    // 1. Flush del testo pendente (se esiste) prima di inserire il blocco speciale
                    if ($currentTextBuffer !== '') {
                        $this->addToAccumulator($accumulator, $currentTextBuffer);
                        $currentTextBuffer = '';

                        // Check se dobbiamo emettere il batch
                        if (count($accumulator) >= $this->generatorChunkSize) {
                            yield $accumulator;
                            $accumulator = [];
                        }
                    }

                    // 2. ARRICCHIMENTO DEL CONTESTO (Context Injection)
                    // Prepariamo il contenuto iniettando i titoli correnti prima della tabella/grafico.
                    $enrichedContent = $rawContent;

                    if (!empty($contextString)) {
                        // Formattiamo in modo che l'LLM capisca che è metadato di contesto
                        $enrichedContent = "**Context:** " . $contextString . "\n\n" . $rawContent;
                    }

                    // 3. Aggiunta al batch (ignorando maxChunkSize per preservare integrità)
                    $this->addToAccumulator($accumulator, $enrichedContent);

                    // Check immediato per yield
                    if (count($accumulator) >= $this->generatorChunkSize) {
                        yield $accumulator;
                        $accumulator = [];
                    }

                    continue; // Passa al prossimo blocco
                }

                // --- CASO B: Testo e Codice Generico (Merging) ---
                // Il testo normale viene concatenato finché c'è spazio.
                // Non iniettiamo il contesto qui perché il testo contiene già i titoli (Headers) al suo interno.

                $separator = ($currentTextBuffer === '') ? '' : "\n\n";
                $projectedSize = strlen($currentTextBuffer) + strlen($rawContent) + strlen($separator);

                if ($projectedSize < $this->maxChunkSize) {
                    // C'è spazio: unisci
                    $currentTextBuffer .= $separator . $rawContent;
                } else {
                    // Non c'è spazio: Flush del vecchio buffer
                    if ($currentTextBuffer !== '') {
                        $this->addToAccumulator($accumulator, $currentTextBuffer);

                        if (count($accumulator) >= $this->generatorChunkSize) {
                            yield $accumulator;
                            $accumulator = [];
                        }
                    }
                    // Inizia il nuovo buffer con il contenuto attuale
                    $currentTextBuffer = $rawContent;
                }
            }

            // Cleanup finale (flush di ciò che è rimasto)
            if ($currentTextBuffer !== '') {
                $this->addToAccumulator($accumulator, $currentTextBuffer);
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
     * "Macchina a Stati" per lo streaming.
     * Legge riga per riga, traccia i titoli (Header Context) e identifica i blocchi.
     * * Restituisce array con:
     * - type: string
     * - content: string
     * - context: string (la breadcrumb dei titoli attivi)
     */
    protected function yieldBlocksFromStream($stream): Generator
    {
        $buffer = [];
        $currentType = self::TYPE_TEXT;

        $inCodeBlock = false;
        $codeFence = ''; // Memorizza se abbiamo aperto con ``` o ~~~

        // Stack per tracciare i titoli gerarchici: [1 => 'H1 Title', 2 => 'H2 Title']
        $headerStack = [];

        while (($line = fgets($stream)) !== false) {
            $trimmedLine = rtrim($line, "\r\n"); // Togliamo newline ma teniamo indentazione a sinistra
            $trimVal = trim($trimmedLine);

            // 0. TRACKING DEI TITOLI (Context)
            // Se troviamo un Header (#, ##, etc) e NON siamo dentro un blocco di codice, aggiorniamo il contesto.
            if (!$inCodeBlock && preg_match('/^(#{1,6})\s+(.*)$/', $trimVal, $matches)) {
                $level = strlen($matches[1]); // Lunghezza di # determina il livello
                $titleText = trim($matches[2]);

                $this->updateHeaderContext($headerStack, $level, $titleText);

                // Nota: L'header viene comunque lasciato scorrere nel buffer del testo ($buffer[] = $line dopo)
                // perché è importante che faccia parte anche del flusso narrativo testuale.
            }

            // 1. RILEVAMENTO CODE BLOCKS / MERMAID
            if (preg_match('/^(\s*)(`{3,}|~{3,})(.*)$/', $trimmedLine, $matches)) {
                $fence = $matches[2]; // ``` o ~~~
                $info = strtolower(trim($matches[3])); // es. "mermaid", "php"

                if (!$inCodeBlock) {
                    // -> INIZIO NUOVO BLOCCO CODICE

                    // Se avevamo roba accumulata (es. testo precedente), la emettiamo
                    if (!empty($buffer)) {
                        yield [
                            'type' => $currentType,
                            'content' => implode("\n", $buffer),
                            'context' => $this->formatContext($headerStack)
                        ];
                        $buffer = [];
                    }

                    $inCodeBlock = true;
                    $codeFence = $fence[0]; // Salviamo il carattere di apertura (` o ~)

                    // Decidiamo il tipo
                    $currentType = str_contains($info, 'mermaid') ? self::TYPE_MERMAID : self::TYPE_CODE;

                    $buffer[] = $trimmedLine;
                    continue;

                } else {
                    // -> FINE BLOCCO CODICE?
                    if (str_starts_with($trimVal, $codeFence)) {
                        $buffer[] = $trimmedLine;

                        // Emettiamo il blocco codice completo (granitico o no, lo decide il chunker)
                        yield [
                            'type' => $currentType,
                            'content' => implode("\n", $buffer),
                            'context' => $this->formatContext($headerStack)
                        ];

                        // Reset stato
                        $buffer = [];
                        $inCodeBlock = false;
                        $currentType = self::TYPE_TEXT;
                        continue;
                    }
                }
            }

            // Se siamo dentro un blocco codice, accumuliamo tutto (anche se sembrano tabelle o header)
            if ($inCodeBlock) {
                $buffer[] = $trimmedLine;
                continue;
            }

            // 2. RILEVAMENTO TABELLE
            // Euristica: riga con pipe '|' all'inizio, alla fine, o multiple in mezzo.
            // Ignoriamo righe troppo corte.
            $isTableLine = (str_starts_with($trimVal, '|') || str_ends_with($trimVal, '|') || mb_substr_count($trimVal, '|') > 1);
            if ($isTableLine && strlen($trimVal) < 3) $isTableLine = false;

            if ($isTableLine) {
                if ($currentType !== self::TYPE_TABLE) {
                    // Cambio contesto: Da Text -> Table
                    if (!empty($buffer)) {
                        yield [
                            'type' => $currentType,
                            'content' => implode("\n", $buffer),
                            'context' => $this->formatContext($headerStack)
                        ];
                        $buffer = [];
                    }
                    $currentType = self::TYPE_TABLE;
                }
                $buffer[] = $trimmedLine;
                continue;
            } else {
                // Se eravamo in una tabella e la riga NON è tabella -> Fine tabella
                if ($currentType === self::TYPE_TABLE && $trimVal !== '') {
                    // Emetti la tabella finita
                    yield [
                        'type' => self::TYPE_TABLE,
                        'content' => implode("\n", $buffer),
                        'context' => $this->formatContext($headerStack)
                    ];
                    $buffer = [];
                    $currentType = self::TYPE_TEXT;
                }
            }

            // 3. GESTIONE TESTO NORMALE

            // Caso speciale: Riga vuota interrompe la tabella
            if ($currentType === self::TYPE_TABLE && $trimVal === '') {
                yield [
                    'type' => self::TYPE_TABLE,
                    'content' => implode("\n", $buffer),
                    'context' => $this->formatContext($headerStack)
                ];
                $buffer = [];
                $currentType = self::TYPE_TEXT;
                continue;
            }

            // Accumuliamo il testo normale
            $buffer[] = $trimmedLine;
        }

        // Flush finale se è rimasto qualcosa nel buffer
        if (!empty($buffer)) {
            yield [
                'type' => $currentType,
                'content' => implode("\n", $buffer),
                'context' => $this->formatContext($headerStack)
            ];
        }
    }

    /**
     * Gestisce lo stack dei titoli.
     * Se arriva un H2, cancella H3, H4, ecc. precedenti.
     */
    private function updateHeaderContext(array &$stack, int $level, string $text): void
    {
        // 1. Imposta il titolo corrente per questo livello
        $stack[$level] = $text;

        // 2. Pulisci tutti i livelli più profondi (es. se entro in H2, esco da H3 vecchio)
        foreach (array_keys($stack) as $key) {
            if ($key > $level) {
                unset($stack[$key]);
            }
        }

        // Ordina per mantenere la sequenza H1 -> H2 -> H3
        ksort($stack);
    }

    /**
     * Formatta lo stack in una stringa leggibile: "Titolo | Sottotitolo"
     */
    private function formatContext(array $stack): string
    {
        return implode(' | ', $stack);
    }

    protected function addToAccumulator(array &$accumulator, string $text): void
    {
        $accumulator[] = new RefinedItemDTO(
            text: trim($text),
            figurePath: null // Markdown inline non ha path esterni separati qui
        );
    }
}
