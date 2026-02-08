<?php

namespace SimoneBianco\LaravelRagChunks\Services\PostProcessors;

use Illuminate\Support\Str;
use SimoneBianco\LaravelRagChunks\AiAgents\PostProcessingAgent;
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
            $neededMap[$item['tags_hash']] = $item['tags'];
            $neededMap[$item['questions_hash']] = $item['questions'];
            $neededMap[$item['text_hash']] = $item['text'];
        }

        $existingEmbeddings = Embedding::whereIn('hash', array_keys($neededMap))
            ->pluck('embedding', 'hash')
            ->toArray();

        $missingHashes = array_diff_key($neededMap, $existingEmbeddings);
        if (!empty($missingHashes)) {
            $textsToEmbed = array_values($missingHashes);

            $newVectors = [];
            foreach ($textsToEmbed as $text) {
                $newVectors[] = retry(
                    config('rag_chunks.embedding_retry.times', 3),
                    fn() => $embedder->embed($text),
                    config('rag_chunks.embedding_retry.sleep', 1000)
                );
            }

            $newEmbeddingsMap = array_combine(array_keys($missingHashes), $newVectors);
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
     * @param string $relativeSourcePath
     * @param string $relativeOutputPath
     * @param string $documentContext
     * @param int $batchSize
     * @return void
     * @throws InvalidEmbeddingDriverException
     * @throws PostProcessingException
     */
    public function postProcess(
        string $relativeSourcePath,
        string $relativeOutputPath,
        string $documentContext = '',
        int $batchSize = 20
    ): void {
        $embedder = EmbeddingFactory::make();
        try {
            $relativeDirPath = pathinfo($relativeSourcePath, PATHINFO_DIRNAME);
            $readStream = $this->fileService->readStream($relativeSourcePath);
            $writeStream = $this->fileService->writeStream($relativeOutputPath);
            $alreadyProcessed = $this->streamService->countLines($writeStream);

            $previousChunkTags = '';
            if ($alreadyProcessed > 0) {
                $this->streamService->goToLine($writeStream, $alreadyProcessed - 1);
                $lastLine = fgets($writeStream);
                if ($lastLine) {
                    $lastItem = json_decode($lastLine, true);
                    $previousChunkTags = $lastItem['tags'] ?? '';
                }
                $this->streamService->goToEnd($writeStream);
            }

            $postProcessingAgent = new PostProcessingAgent(Str::random())->withDocumentContext($documentContext);

            $currentInputLine = 0;
            $buffer = [];
            while (($line = fgets($readStream)) !== false) {
                $currentInputLine++;

                if ($currentInputLine <= $alreadyProcessed || trim($line) === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if ($decoded === null) {
                    continue;
                }
                $item = RefinedItemDTO::fromArray($decoded);
                $response = $postProcessingAgent
                    ->clear()
                    ->withPreviousChunkTags($previousChunkTags)
                    ->respondAndGetFormattedResults($item->text);

                $tags = $response->getImplodedTags();
                $questions = $response->getImplodedQuestions();

                $previousChunkTags = $tags;

                $buffer[] = [
                    'text' => $item->text,
                    'figure_path' => !empty($item->figurePath) ? "$relativeDirPath/$item->figurePath" : null,
                    'text_hash' => HashService::hash($item->text),
                    'tags' => $tags,
                    'tags_hash' => HashService::hash($tags),
                    'questions' => $questions,
                    'questions_hash' => HashService::hash($questions),
                ];

                if (count($buffer) >= $batchSize) {
                    $this->processPostProcessingBuffer($buffer, $writeStream, $embedder);
                }
            }

            if (!empty($buffer)) {
                $this->processPostProcessingBuffer($buffer, $writeStream, $embedder);
            }
        } catch (Throwable $exception) {
            if (isset($writeStream)) {
                if (!empty($buffer)) {
                    try {
                        $this->processPostProcessingBuffer($buffer, $writeStream, $embedder);
                    } catch (Throwable $rescueException) {}
                }

                $this->fileService->closeStreams($readStream ?? null, $writeStream);
            }

            throw new PostProcessingException(
                "Error during post-processing: {$exception->getMessage()}",
                0,
                $exception,
                get_class($exception),
                $relativeSourcePath,
                $currentInputLine ?? 0,
                $line ?? '',
                false
            );
        }

        $this->fileService->closeStreams($readStream, $writeStream);
    }
}
