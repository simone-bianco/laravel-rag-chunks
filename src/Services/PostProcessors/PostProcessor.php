<?php

namespace SimoneBianco\LaravelRagChunks\Services\PostProcessors;

use Illuminate\Support\Str;
use SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing\PostProcessingAgent;
use SimoneBianco\LaravelRagChunks\Drivers\Embedding\Contracts\EmbeddingDriverInterface;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\PostProcessedItemDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\RefinedItemDTO;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidEmbeddingDriverException;
use SimoneBianco\LaravelRagChunks\Exceptions\PostProcessingException;
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
                questionsEmbedding: $existingEmbeddings[$item['questions_hash']] ?? null
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
        ?string $documentContext
    ): array {
        if (empty($pendingItems)) {
            return [];
        }

        // ORA PASSIAMO ALL'AI SIA IL TESTO CHE IL FIGURE PATH, così sa che immagine ha!
        $chunksPayload = array_map(function ($item) use ($relativeDirPath) {
            return [
                'text' => $item->text,
                // Pre-assembliamo il path qui, in modo che l'AI debba solo restituirlo testualmente
                'figure_path' => !empty($item->figurePath) ? "$relativeDirPath/{$item->figurePath}" : null,
            ];
        }, $pendingItems);

        // Istanzia e chiama l'agent
        $postProcessingAgent = new PostProcessingAgent(Str::random());
        $agentResponse = $postProcessingAgent
            ->withDocumentContext($documentContext)
            ->withChunks($chunksPayload)
            ->respond();

        $buffer = [];

        // Ricostruisci il buffer iterando sulla risposta dell'AI
        foreach ($agentResponse as $aiData) {
            $content = $aiData['content'] ?? '';

            if (empty($content)) {
                continue;
            }

            $tags = $aiData['tags'] ?? [];
            $questions = $aiData['questions'] ?? [];

            // Recuperiamo il figure_path che l'AI ha deciso di associare a questo chunk dinamico
            $figurePath = !empty($aiData['figure_path']) ? $aiData['figure_path'] : null;

            $buffer[] = [
                'text' => $content,
                'figure_path' => $figurePath,
                'text_hash' => HashService::hash($content),
                'tags' => $tags,
                'tags_hash' => HashService::hash(implode(',', $tags)),
                'questions' => $questions,
                'questions_hash' => HashService::hash(implode('?', $questions)),
            ];
        }

        return $buffer;
    }

    /**
     * @param string $relativeSourcePath
     * @param string $relativeOutputPath
     * @param string|null $documentContext
     * @param int $batchSize
     * @return void
     * @throws InvalidEmbeddingDriverException
     * @throws PostProcessingException
     */
    public function postProcess(
        string $relativeSourcePath,
        string $relativeOutputPath,
        ?string $documentContext = '',
        int $batchSize = 10
    ): void {
        $embedder = EmbeddingFactory::make();

        // Variabili per gestione errori
        $currentInputLine = 0;
        $lastProcessedLineContent = '';

        try {
            $relativeDirPath = pathinfo($relativeSourcePath, PATHINFO_DIRNAME);
            $readStream = $this->fileService->readStream($relativeSourcePath);
            $writeStream = $this->fileService->writeStream($relativeOutputPath);

            // Calcola dove riprendere
            $alreadyProcessed = $this->streamService->countLines($writeStream);
            if ($alreadyProcessed > 0) {
                $this->streamService->goToEnd($writeStream);
            }

            $pendingBatch = []; // Conterrà oggetti RefinedItemDTO

            while (($line = fgets($readStream)) !== false) {
                $currentInputLine++;
                $lastProcessedLineContent = $line;

                // Salta righe già processate o vuote
                if ($currentInputLine <= $alreadyProcessed || trim($line) === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if ($decoded === null) {
                    continue;
                }

                // Aggiungi al batch corrente
                $pendingBatch[] = RefinedItemDTO::fromArray($decoded);

                // Se il batch è pieno, processalo
                if (count($pendingBatch) >= $batchSize) {
                    $buffer = $this->runAgentAndPrepareBuffer($pendingBatch, $relativeDirPath, $documentContext);
                    $this->processPostProcessingBuffer($buffer, $writeStream, $embedder);
                    $pendingBatch = []; // Reset batch
                }
            }

            // Processa eventuali elementi rimasti nel batch
            if (!empty($pendingBatch)) {
                $buffer = $this->runAgentAndPrepareBuffer($pendingBatch, $relativeDirPath, $documentContext);
                $this->processPostProcessingBuffer($buffer, $writeStream, $embedder);
            }

        } catch (Throwable $exception) {
            // Tentativo di salvataggio del buffer in memoria in caso di crash
            if (isset($writeStream) && isset($embedder) && !empty($pendingBatch)) {
                try {
                    // Proviamo a processare quello che è rimasto, se possibile
                    $buffer = $this->runAgentAndPrepareBuffer($pendingBatch, $relativeDirPath ?? '', $documentContext);
                    $this->processPostProcessingBuffer($buffer, $writeStream, $embedder);
                } catch (Throwable $rescueException) {
                    // Ignoriamo errori nel rescue per non oscurare l'errore originale
                }
            }

            if (isset($readStream)) {
                $this->fileService->closeStreams($readStream, $writeStream ?? null);
            } else if (isset($writeStream)) {
                $this->fileService->closeStreams(null, $writeStream);
            }

            $prev = $exception->getPrevious();
            $isRetryable = ($exception instanceof \RuntimeException && $prev instanceof \TypeError)
                || $prev instanceof \GuzzleHttp\Exception\ConnectException
                || $prev instanceof \GuzzleHttp\Exception\ServerException
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
