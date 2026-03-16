<?php

namespace SimoneBianco\LaravelRagChunks\Services\PostProcessors;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing\PostProcessingAgent;
use SimoneBianco\LaravelRagChunks\Drivers\Embedding\Contracts\EmbeddingDriverInterface;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\PostProcessedItemDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\RefinedItemDTO;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidEmbeddingDriverException;
use SimoneBianco\LaravelRagChunks\Exceptions\PostProcessingException;
use SimoneBianco\LaravelRagChunks\Exceptions\ProcessStoppedException;
use SimoneBianco\LaravelRagChunks\Facades\HashService;
use SimoneBianco\LaravelRagChunks\Factories\EmbeddingFactory;
use SimoneBianco\LaravelRagChunks\Models\Embedding;
use SimoneBianco\LaravelRagChunks\Services\FileService;
use SimoneBianco\LaravelRagChunks\Services\StreamService;
use Throwable;

class PostProcessor
{
    public function __construct(
        protected FileService $fileService,
        protected StreamService $streamService
    ) {}

    /**
     * @param array $items
     * @param $writeStream
     * @param EmbeddingDriverInterface $embedder
     * @return void
     * @throws Throwable
     */
    protected function processPostProcessingBuffer(
        array &$items,
              $writeStream,
        EmbeddingDriverInterface $embedder
    ): void {
        if (empty($items)) return;

        $neededMap = [];
        foreach ($items as $item) {
            // Assicuriamoci che i dati esistano prima di fare implode/hash
            $tagsStr = isset($item['tags']) ? implode(',', $item['tags']) : '';
            $questionsStr = isset($item['questions']) ? implode('?', $item['questions']) : '';

            $neededMap[$item['tags_hash']] = $tagsStr;
            $neededMap[$item['questions_hash']] = $questionsStr;
            $neededMap[$item['text_hash']] = $item['text'];
        }

        $existingEmbeddings = Embedding::whereIn('hash', array_keys($neededMap))
            ->pluck('embedding', 'hash')
            ->toArray();

        $missingHashes = array_diff_key($neededMap, $existingEmbeddings);
        if (!empty($missingHashes)) {
            $textsToEmbed = array_values($missingHashes);

            $newVectors = Embedding::multiEmbed($textsToEmbed);

            $newEmbeddingsMap = array_combine(array_keys($missingHashes), $newVectors);
            $newEmbeddingsMap = array_filter($newEmbeddingsMap);
            $existingEmbeddings = $existingEmbeddings + $newEmbeddingsMap;
        }

        foreach ($items as $key => $item) {
            $postProcessedItem = new PostProcessedItemDTO(
                text: $item['text'],
                figurePath: $item['figure_path'],
                textHash: $item['text_hash'],
                textEmbedding: $existingEmbeddings[$item['text_hash']] ?? null,
                tags: $item['tags'],
                tagsHash: $item['tags_hash'],
                tagsEmbedding: $existingEmbeddings[$item['tags_hash']] ?? null,
                questions: $item['questions'],
                questionsHash: $item['questions_hash'],
                questionsEmbedding: $existingEmbeddings[$item['questions_hash']] ?? null,
                deterministicTags: $item['deterministic_tags'] ?? null,
                chapter: $item['chapter'] ?? null,
            );

            $this->fileService->writeOnStream(
                $writeStream,
                json_encode($postProcessedItem->toArray(), JSON_UNESCAPED_UNICODE) . "\n"
            );

            unset($items[$key]);
        }
    }

    /**
     * Helper per processare il batch con l'agent e preparare l'array per il buffer
     */
    protected function runAgentAndPrepareBuffer(
        array $pendingItems,
        string $relativeDirPath,
        ?string $documentContext,
        array $agentOptions = [],
        ?string $batchContextBefore = null,
        ?string $batchContextAfter = null,
        ?string $activeContextFromPreviousChunking = null,
        ?string $usefulInfoFromPreviousChunking = null,
        array &$currentIndex = [],
    ): array {
        if (empty($pendingItems)) {
            return [];
        }

        Log::channel('document-queue')->debug('[PostProcessor] iteration input', [
            'pending_items_count' => count($pendingItems),
            'create_index' => !empty($agentOptions['create_index']),
            'current_index_count' => count($currentIndex),
            'current_index_values' => $currentIndex,
            'incoming_active_context' => $this->truncateForLog($activeContextFromPreviousChunking),
            'incoming_useful_info_present' => $usefulInfoFromPreviousChunking !== null && trim($usefulInfoFromPreviousChunking) !== '',
            'incoming_useful_info' => $this->truncateForLog($usefulInfoFromPreviousChunking),
            'batch_context_before' => $this->truncateForLog($batchContextBefore),
            'batch_context_after' => $this->truncateForLog($batchContextAfter),
        ]);

        // ORA PASSIAMO ALL'AI SIA IL TESTO CHE IL FIGURE PATH, così sa che immagine ha!
        $chunksPayload = array_map(function ($item) use ($relativeDirPath) {
            return [
                'text' => $item->text,
                // Pre-assembliamo il path qui, in modo che l'AI debba solo restituirlo testualmente
                'figure_path' => !empty($item->figurePath) ? "$relativeDirPath/{$item->figurePath}" : null,
            ];
        }, $pendingItems);

        // Istanzia e chiama l'agent con le opzioni del context del processo
        $postProcessingAgent = new PostProcessingAgent(Str::random(), $agentOptions);
        $agentResponse = $postProcessingAgent
            ->withDocumentContext($documentContext)
            ->withChunks($chunksPayload)
            ->withPreferredChunkLength($agentOptions['preferred_chunk_length'] ?? 600)
            ->withContextInjection($agentOptions['context_injection'] ?? false)
            ->withCleanText($agentOptions['clean_text'] ?? false)
            ->withSummarization($agentOptions['summarization'] ?? false)
            ->withExtraInstructions($agentOptions['extra_instructions'] ?? null)
            ->withTagsByType($agentOptions['tags_by_type'] ?? [])
            ->withCreateIndex(!empty($agentOptions['create_index']))
            ->withCurrentIndex($currentIndex)
            ->withActiveContextFromPreviousChunking($activeContextFromPreviousChunking)
            ->withBatchBoundaryContext($batchContextBefore, $batchContextAfter)
            ->withUsefulInfoFromPreviousChunking($usefulInfoFromPreviousChunking)
            ->respond();

        $chunksResponse = $agentResponse['chunks'] ?? $agentResponse;
        $activeContextForNextChunking = '';
        if (is_array($agentResponse) && is_string($agentResponse['active_context_for_next_chunking'] ?? null)) {
            $activeContextForNextChunking = $this->normalizeActiveContext($agentResponse['active_context_for_next_chunking']);
        }
        $usefulInfoForNextChunking = '';
        if (is_array($agentResponse) && is_string($agentResponse['useful_info_for_next_chunking'] ?? null)) {
            $usefulInfoForNextChunking = trim($agentResponse['useful_info_for_next_chunking']);
        }

        if ($batchContextAfter === null
            && $usefulInfoForNextChunking !== ''
            && !$this->containsTruncationSignal($usefulInfoForNextChunking)) {
            Log::channel('document-queue')->debug('[PostProcessor] dropping speculative useful_info on last batch', [
                'useful_info' => $this->truncateForLog($usefulInfoForNextChunking),
            ]);
            $usefulInfoForNextChunking = '';
        }

        $buffer = [];

        // Ricostruisci il buffer iterando sulla risposta dell'AI
        foreach ($chunksResponse as $aiData) {
            $content = $aiData['content'] ?? '';

            if (empty($content)) {
                continue;
            }

            $tags = $aiData['tags'] ?? [];
            $questions = $aiData['questions'] ?? [];
            $chapter = null;

            if (!empty($agentOptions['create_index'])) {
                $chapterTitle = $this->normalizeChapterTitle((string)($aiData['chapter_title'] ?? ''));
                if ($chapterTitle !== '') {
                    $chapter = $this->toChapterAlias($chapterTitle);
                    if (is_string($chapter) && $chapter !== '') {
                        $this->addCurrentIndexTitle($currentIndex, $chapterTitle);
                    } else {
                        $chapter = null;
                    }
                }
            }

            // Recuperiamo il figure_path che l'AI ha deciso di associare a questo chunk dinamico
            $figurePath = !empty($aiData['figure_path']) ? $aiData['figure_path'] : null;

            // Estrai i tag deterministici (tags_$type) se assign_tags era abilitato
            $deterministicTags = null;
            foreach (array_keys($agentOptions['tags_by_type'] ?? []) as $type) {
                $fieldKey = "tags_$type";
                if (!empty($aiData[$fieldKey])) {
                    $deterministicTags[$type] = $aiData[$fieldKey];
                }
            }

            $buffer[] = [
                'text' => $content,
                'figure_path' => $figurePath,
                'text_hash' => HashService::hash($content),
                'tags' => $tags,
                'tags_hash' => HashService::hash(implode(',', $tags)),
                'questions' => $questions,
                'questions_hash' => HashService::hash(implode('?', $questions)),
                'deterministic_tags' => $deterministicTags,
                'chapter' => $chapter,
            ];
        }

        Log::channel('document-queue')->debug('[PostProcessor] iteration output', [
            'buffer_items_count' => count($buffer),
            'outgoing_active_context' => $this->truncateForLog($activeContextForNextChunking),
            'outgoing_useful_info_present' => $usefulInfoForNextChunking !== '',
            'outgoing_useful_info' => $this->truncateForLog($usefulInfoForNextChunking),
            'current_index_count_after' => count($currentIndex),
            'current_index_values_after' => $currentIndex,
        ]);

        return [
            'buffer' => $buffer,
            'active_context_for_next_chunking' => $activeContextForNextChunking,
            'useful_info_for_next_chunking' => $usefulInfoForNextChunking,
        ];
    }

    protected function truncateForLog(?string $value, int $max = 1200): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max) . '...';
    }

    protected function toChapterAlias(string $chapterTitle): ?string
    {
        $normalizedTitle = strtolower(trim($chapterTitle));
        if ($normalizedTitle === '') {
            return null;
        }

        $alias = preg_replace('/[^a-z0-9]+/', '-', $normalizedTitle) ?? '';
        $alias = preg_replace('/-+/', '-', $alias) ?? '';
        $alias = trim($alias, '-');

        return $alias !== '' ? $alias : null;
    }

    protected function normalizeChapterTitle(string $chapterTitle): string
    {
        $chapterTitle = trim($chapterTitle);
        $chapterTitle = preg_replace('/\s+/', ' ', $chapterTitle) ?? $chapterTitle;
        return trim($chapterTitle);
    }

    protected function addCurrentIndexTitle(array &$currentIndex, string $chapterTitle): void
    {
        $normalizedKey = strtolower($chapterTitle);
        foreach ($currentIndex as $existingTitle) {
            if (strtolower((string)$existingTitle) === $normalizedKey) {
                return;
            }
        }

        $currentIndex[] = $chapterTitle;
    }

    protected function normalizeActiveContext(?string $context): string
    {
        if ($context === null) {
            return '';
        }

        $normalized = trim($context);
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[_\-]+/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        return trim(mb_strtolower($normalized));
    }

    protected function containsTruncationSignal(string $value): bool
    {
        return preg_match('/truncat|unfinished|cut|fragment|ends with|continuation|continues?/i', $value) === 1;
    }

    /**
     * @param string $relativeSourcePath
     * @param string $relativeOutputPath
     * @param string|null $documentContext
     * @param int $batchSize
     * @param array $agentOptions
     * @param int $startFromInputLine  Numero di righe input già processate (resume). 0 = partenza fresca.
     * @param callable|null $onBatchComplete  Callback chiamata dopo ogni batch con l'ultima riga input processata.
     * @throws InvalidEmbeddingDriverException
     * @throws PostProcessingException
     */
    public function postProcess(
        string $relativeSourcePath,
        string $relativeOutputPath,
        ?string $documentContext = '',
        int $batchSize = 8,
        array $agentOptions = [],
        int $startFromInputLine = 0,
        ?callable $onBatchComplete = null
    ): void {
        $embedder = EmbeddingFactory::make();

        $currentInputLine = 0;
        $lastProcessedLineContent = '';

        try {
            $relativeDirPath = pathinfo($relativeSourcePath, PATHINFO_DIRNAME);
            $readStream = $this->fileService->readStream($relativeSourcePath);
            $writeStream = $this->fileService->writeStream($relativeOutputPath);

            // Se stiamo facendo resume, vai in fondo al file di output per appendere
            if ($startFromInputLine > 0) {
                $this->streamService->goToEnd($writeStream);
            }

            $overlapSize = (int) config('rag_chunks.chunk_overlap', 150);
            $pendingBatch = [];
            $previousTailContext = null; // ultimi N char del testo dell'ultimo item del batch precedente
            $activeContextForNextChunking = null;
            $usefulInfoForNextChunking = null;
            $currentIndex = [];

            while (($line = fgets($readStream)) !== false) {
                $currentInputLine++;
                $lastProcessedLineContent = $line;

                // Salta le righe input già processate nella sessione precedente
                if ($currentInputLine <= $startFromInputLine || trim($line) === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if ($decoded === null) {
                    continue;
                }

                $pendingBatch[] = RefinedItemDTO::fromArray($decoded);

                if (count($pendingBatch) >= $batchSize) {
                    // Cattura il contesto di coda PRIMA di svuotare il batch
                    $tailText = end($pendingBatch)->text;
                    $tailContext = $overlapSize > 0 ? mb_substr($tailText, -$overlapSize) : null;

                    // Salva la posizione corrente PRIMA del lookahead, così il callback
                    // riporta esattamente le righe elaborate in questo batch
                    $batchEndLine = $currentInputLine;

                    // Lookahead: leggi il prossimo item per ottenere il contesto di prefisso del batch successivo
                    $suffixContext = null;
                    $lookaheadItem = null;
                    while (($lookaheadLine = fgets($readStream)) !== false) {
                        $currentInputLine++;
                        $lastProcessedLineContent = $lookaheadLine;
                        if (trim($lookaheadLine) === '') {
                            continue; // salta righe vuote e prosegui il lookahead
                        }
                        $lookaheadDecoded = json_decode($lookaheadLine, true);
                        if ($lookaheadDecoded === null) {
                            continue; // riga non valida, salta
                        }
                        $lookaheadItem = RefinedItemDTO::fromArray($lookaheadDecoded);
                        if ($overlapSize > 0) {
                            $suffixContext = mb_substr($lookaheadItem->text, 0, $overlapSize);
                        }
                        break;
                    }

                    $agentProcessingResult = $this->runAgentAndPrepareBuffer(
                        $pendingBatch, $relativeDirPath, $documentContext, $agentOptions,
                        $previousTailContext, $suffixContext, $activeContextForNextChunking, $usefulInfoForNextChunking, $currentIndex
                    );
                    $this->processPostProcessingBuffer($agentProcessingResult['buffer'], $writeStream, $embedder);
                    $activeContextForNextChunking = $agentProcessingResult['active_context_for_next_chunking'] ?? null;
                    $usefulInfoForNextChunking = $agentProcessingResult['useful_info_for_next_chunking'] ?? null;

                    $previousTailContext = $tailContext;
                    // Inizia il prossimo batch con il lookahead già letto, se disponibile
                    $pendingBatch = $lookaheadItem ? [$lookaheadItem] : [];

                    if ($onBatchComplete) {
                        $onBatchComplete($batchEndLine);
                    }
                }
            }

            if (!empty($pendingBatch)) {
                $agentProcessingResult = $this->runAgentAndPrepareBuffer(
                    $pendingBatch, $relativeDirPath, $documentContext, $agentOptions,
                    $previousTailContext, null, $activeContextForNextChunking, $usefulInfoForNextChunking, $currentIndex
                );
                $this->processPostProcessingBuffer($agentProcessingResult['buffer'], $writeStream, $embedder);
                if ($onBatchComplete) {
                    $onBatchComplete($currentInputLine);
                }
            }

        } catch (Throwable $exception) {
            if (isset($readStream)) {
                $this->fileService->closeStreams($readStream, $writeStream ?? null);
            } elseif (isset($writeStream)) {
                $this->fileService->closeStreams(null, $writeStream);
            }

            // Let the stop signal exception pass through without wrapping
            if ($exception instanceof ProcessStoppedException) {
                throw $exception;
            }

            $prev = $exception->getPrevious();
            $isRetryable = ($exception instanceof \RuntimeException && $prev instanceof \TypeError)
                || $prev instanceof ConnectException
                || $prev instanceof ServerException
                || $exception instanceof \OpenAI\Exceptions\UnserializableResponse
                || $exception instanceof \JsonException
                || str_contains($exception->getMessage(), 'Syntax error')
                || str_contains($exception->getMessage(), 'timed out')
                || str_contains($exception->getMessage(), 'Connection refused')
                || str_contains($exception->getMessage(), 'cURL error');

            throw new PostProcessingException(
                "Error during post-processing: {$exception->getMessage()}",
                0,
                $exception,
                get_class($exception),
                $relativeSourcePath,
                $currentInputLine ?? 0,
                $lastProcessedLineContent ?? '',
                $isRetryable
            );
        }

        $this->fileService->closeStreams($readStream, $writeStream);
    }
}
