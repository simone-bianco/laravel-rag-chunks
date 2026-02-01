<?php

namespace SimoneBianco\LaravelRagChunks\Services\Parsers;

use Illuminate\Support\Str;
use SimoneBianco\LaravelRagChunks\AiAgents\PostProcessingAgent;
use SimoneBianco\LaravelRagChunks\Drivers\Embedding\Contracts\EmbeddingDriverInterface;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\Pdf\PollingContextDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\Pdf\PostProcessingContextDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\Pdf\RefiningContextDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\PostProcessedItemDTO;
use SimoneBianco\LaravelRagChunks\DTOs\Parsing\RefinedItemDTO;
use SimoneBianco\LaravelRagChunks\Exceptions\PostProcessingException;
use SimoneBianco\LaravelRagChunks\Facades\HashService;
use SimoneBianco\LaravelRagChunks\Factories\EmbeddingFactory;
use SimoneBianco\LaravelRagChunks\Models\Embedding;
use SimoneBianco\LaravelRagChunks\Services\StreamService;
use SimoneBianco\SimpleStorageClient\Exceptions\ConnectionFailedException;
use SimoneBianco\SimpleStorageClient\Exceptions\SimpleStorageException;
use SimoneBianco\SimpleStorageClient\Exceptions\UnauthorizedException;
use SimoneBianco\SimpleStorageClient\SimpleStorageClient;
use Illuminate\Contracts\Filesystem\Filesystem;
use JsonMachine\Exception\InvalidArgumentException;
use SimoneBianco\DolphinParser\DolphinParserClient;
use SimoneBianco\LaravelRagChunks\Enums\ParserStatus;
use SimoneBianco\LaravelRagChunks\Exceptions\ClientException;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;
use SimoneBianco\LaravelRagChunks\Services\Chunkers\DolphinOutputChunkerService;
use SimoneBianco\LaravelRagChunks\Services\FileService;
use SimoneBianco\LaravelRagChunks\Services\Parsers\Contracts\DocumentParserInterface;
use Throwable;

class PdfParser implements DocumentParserInterface
{
    public function __construct(
        protected FileService                 $fileService,
        protected DolphinParserClient         $dolphinParser,
        protected DolphinOutputChunkerService $dolphinOutputChunker,
        protected SimpleStorageClient         $simpleStorage,
        protected StreamService               $streamService,
    ) {}

    protected function getRelativeTempPath(): string
    {
        return 'temp';
    }

    public function needsPolling(): bool
    {
        return true;
    }

    /**
     * @param string $absolutePath
     * @return array
     * @throws ClientException
     */
    public function dispatchParsing(string $absolutePath): array
    {
        try {
            $response = $this->dolphinParser->parseFileAsync($absolutePath);
        } catch (Throwable $exception) {
            throw ClientException::makeFromException($exception);
        }

        return new PollingContextDTO($response->jobId)->toArray();
    }

    /**
     * @param array $data
     * @return ParserStatus
     * @throws ClientException
     */
    public function pollParsing(array $data): ParserStatus
    {
        try {
            $context = PollingContextDTO::fromArray($data);

            $response = $this->dolphinParser->status($context->jobId);
        } catch (Throwable $exception) {
            throw ClientException::makeFromException($exception);
        }

        if ($response->isFailed()) {
            throw new ClientException(
                "Parsing processing failed: $response->error",
                0,
                null,
                null,
                $response->toArray()
            );
        }

        if ($response->isSuccess()) {
            return ParserStatus::COMPLETED;
        }

        return ParserStatus::PROCESSING;
    }

    /**
     * @param array $data
     * @return array
     * @throws ClientException
     * @throws InvalidFileException
     */
    public function saveParsingResult(array $data): array
    {
        $jobId = PollingContextDTO::fromArray($data)->jobId;

        try {
            if (!$jobId || !$this->simpleStorage->exists($jobId)) {
                throw new \InvalidArgumentException("Job '$jobId' not found");
            }

            $path = sprintf(
                '%s%s%s%s%s.zip',
                $this->getRelativeTempPath(),
                DIRECTORY_SEPARATOR,
                $jobId,
                DIRECTORY_SEPARATOR,
                $jobId
            );
            $targetAbsolutePath = $this->fileService->getAbsolutePath("$path");
            $this->simpleStorage->downloadTo($jobId, $targetAbsolutePath, true);

            return new RefiningContextDTO($this->extractParsingResult($path))->toArray();
        } catch (SimpleStorageException|ConnectionFailedException|UnauthorizedException $exception) {
            throw ClientException::makeFromException($exception);
        }
    }

    protected function storage(): ?Filesystem
    {
        return $this->fileService->getStorage();
    }

    /**
     * @param string $zipRelativePath
     * @param bool $deleteLocal
     * @return string Returns the relative path to the directory containing the parsed result
     * @throws InvalidFileException
     */
    public function extractParsingResult(string $zipRelativePath, bool $deleteLocal = true): string
    {
        $dirRelativePath = $this->fileService->extract($zipRelativePath);

        if ($deleteLocal) {
            $this->storage()->delete($zipRelativePath);
        }

        return $dirRelativePath;
    }

    /**
     * @param array $data
     * @return array
     * @throws InvalidFileException
     */
    public function refineOutputJson(array $data): array
    {
        try {
            $dirRelativePath = RefiningContextDTO::fromArray($data)->relativeDirPath;

            /** @var string $outputJsonRelativePath */
            $outputJsonRelativePath = collect($this->storage()->files($dirRelativePath))->first(function ($file) {
                return pathinfo($file, PATHINFO_EXTENSION) === 'json';
            });

            if (!$this->storage()->exists($outputJsonRelativePath)) {
                throw new InvalidFileException("$outputJsonRelativePath does not exist");
            }

            $writeRelativePath = "$dirRelativePath/refined_output.jsonl";
            $writeAbsolutePath = $this->storage()->path($writeRelativePath);

            $directory = dirname($writeAbsolutePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $stream = fopen($writeAbsolutePath, 'w');

            foreach ($this->dolphinOutputChunker->chunkOutputJson($outputJsonRelativePath) as $chunks) {
                /** @var RefinedItemDTO $row */
                foreach ($chunks as $row) {
                    fwrite($stream, json_encode($row->toArray(), JSON_UNESCAPED_UNICODE) . "\n");
                }
            }
            fclose($stream);

            $this->storage()->delete($outputJsonRelativePath);

            return new PostProcessingContextDTO($dirRelativePath, $writeRelativePath)->toArray();
        } catch (InvalidArgumentException $exception) {
            throw new InvalidFileException(message: $exception->getMessage(), previous: $exception);
        }
    }

    protected function processPostProcessingBuffer(
        iterable $items,
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
                $newVectors[] = $embedder->embed($text);
            }

            $newEmbeddingsMap = array_combine(array_keys($missingHashes), $newVectors);
            $existingEmbeddings = $existingEmbeddings + $newEmbeddingsMap;
        }

        foreach ($items as $item) {
            $postProcessedItem = new PostProcessedItemDTO(
                text: $item['text'],
                figures: $item['figures'],
                textHash: $item['text_hash'],
                textEmbedding: $existingEmbeddings[$item['text_hash']],
                tags: $item['tags'],
                tagsHash: $item['tags_hash'],
                tagsEmbedding: $existingEmbeddings[$item['tags_hash']],
                questions: $item['questions'],
                questionsHash: $item['questions_hash'],
                questionsEmbedding: $existingEmbeddings[$item['questions_hash']]
            );

            fwrite($writeStream, json_encode($postProcessedItem->toArray(), JSON_UNESCAPED_UNICODE) . "\n");
        }
    }

    /**
     * @param string $documentContext
     * @param array $data
     * @param int $batchSize
     * @return array
     * @throws InvalidFileException
     * @throws PostProcessingException
     */
    public function postProcess(string $documentContext, array $data, int $batchSize = 50): array
    {
        $postProcessingData = PostProcessingContextDTO::fromArray($data);

        if (!$this->storage()->exists($postProcessingData->relativeRefinedPath)) {
            throw new InvalidFileException("$postProcessingData->relativeRefinedPath does not exist");
        }

        $relativePostProcessedOutputPath = "$postProcessingData->relativeDirPath/post_processed.jsonl";
        if (!$this->storage()->exists($relativePostProcessedOutputPath)) {
            $this->storage()->put($relativePostProcessedOutputPath, '');
        }
        $postProcessingData->relativePostProcessedPath = $relativePostProcessedOutputPath;

        try {
            $readStream = $this->storage()->readStream($postProcessingData->relativeRefinedPath);

            $writeStream = fopen($this->storage()->path($relativePostProcessedOutputPath), 'a+');
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

            $embedder = EmbeddingFactory::make();
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
                    'figures' => $item->figures,
                    'text_hash' => HashService::hash($item->text),
                    'tags' => $tags,
                    'tags_hash' => HashService::hash($tags),
                    'questions' => $questions,
                    'questions_hash' => HashService::hash($questions),
                ];

                if (count($buffer) >= $batchSize) {
                    $this->processPostProcessingBuffer($buffer, $writeStream, $embedder);
                    $buffer = [];
                }
            }

            if (!empty($buffer)) {
                $this->processPostProcessingBuffer($buffer, $writeStream, $embedder);
            }
        } catch (Throwable $exception) {
            if (isset($readStream)) fclose($readStream);
            if (isset($writeStream)) fclose($writeStream);

            throw new PostProcessingException(
                "Error during PDF post-processing: {$exception->getMessage()}",
                0,
                $exception,
                get_class($exception),
                $postProcessingData->relativeRefinedPath,
                $currentInputLine ?? '',
                $line ?? '',
                false
            );
        }

        fclose($readStream);
        fclose($writeStream);

        return $postProcessingData->toArray();
    }
}
