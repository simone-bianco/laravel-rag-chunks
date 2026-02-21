<?php

namespace SimoneBianco\LaravelRagChunks\Services\Chunkers;

use Generator;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\IOFactory;
use RuntimeException;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\RefinedItemDTO;

class WordChunkerService
{
    public function __construct(
        protected int $chunkSize = 1000,
        protected int $generatorChunkSize = 50,
    ) {}

    /**
     * Entry point. Estrae il testo dal .docx e lo chunka in modo grezzo per dimensione.
     */
    public function chunkWord(string $absolutePath): Generator
    {
        if (!file_exists($absolutePath)) {
            throw new RuntimeException("File non trovato: $absolutePath");
        }

        $phpWord = IOFactory::load($absolutePath);
        $fullText = $this->extractText($phpWord);

        yield from $this->yieldChunks($fullText);
    }

    /**
     * Loads the .docx and returns the full extracted plain text as a single string.
     * Intended for converters (e.g. NaiveWordConverter) that need the raw text
     * before writing it to a .txt file.
     *
     * @param string $absolutePath Absolute path to the .docx file.
     * @return string              The extracted plain text.
     * @throws RuntimeException    If the file does not exist.
     */
    public function extractFullText(string $absolutePath): string
    {
        if (!file_exists($absolutePath)) {
            throw new RuntimeException("File not found: $absolutePath");
        }

        return $this->extractText(IOFactory::load($absolutePath));
    }

    /**
     * Estrae il testo grezzo da tutte le sezioni del documento.
     */
    protected function extractText(\PhpOffice\PhpWord\PhpWord $phpWord): string
    {
        $parts = [];

        foreach ($phpWord->getSections() as $section) {
            $parts[] = $this->extractFromContainer($section);
        }

        return implode("\n\n", array_filter($parts));
    }

    /**
     * Ricorsivamente estrae testo da un container di elementi PhpWord.
     */
    protected function extractFromContainer(AbstractContainer $container): string
    {
        $lines = [];

        foreach ($container->getElements() as $element) {
            $text = match (true) {
                $element instanceof Title      => $this->extractFromElement($element->getText()),
                $element instanceof ListItem   => $this->extractFromElement($element->getTextObject()),
                $element instanceof TextRun    => $this->extractFromContainer($element),
                $element instanceof Text       => $element->getText(),
                $element instanceof AbstractContainer => $this->extractFromContainer($element),
                default                        => null,
            };

            if ($text !== null && trim($text) !== '') {
                $lines[] = trim($text);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Gestisce il caso in cui getText() ritorni una stringa o un oggetto Text.
     */
    protected function extractFromElement(mixed $element): string
    {
        if (is_string($element)) {
            return $element;
        }

        if ($element instanceof Text) {
            return $element->getText();
        }

        return '';
    }

    /**
     * Splitta il testo in chunk grezzi da $chunkSize caratteri e li emette a batch.
     */
    protected function yieldChunks(string $text): Generator
    {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        $accumulator = [];
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            $chunk = substr($text, $offset, $this->chunkSize);
            $offset += $this->chunkSize;

            $trimmed = trim($chunk);
            if ($trimmed === '') {
                continue;
            }

            $accumulator[] = new RefinedItemDTO(text: $trimmed);

            if (count($accumulator) >= $this->generatorChunkSize) {
                yield $accumulator;
                $accumulator = [];
            }
        }

        if (!empty($accumulator)) {
            yield $accumulator;
        }
    }
}
