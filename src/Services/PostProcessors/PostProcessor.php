<?php

namespace SimoneBianco\LaravelRagChunks\Services\PostProcessors;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing\ImagePostProcessingAgent;
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
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class PostProcessor
{
    private const MIN_CHARACTERS_FOR_PAGE_PROCESSING = 100;

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
        int $processedChunksCount = 0,
        int $totalDocumentChunks = 0,
    ): array {
        if (empty($pendingItems)) {
            return [];
        }

        Log::channel('document-queue')->debug('[PostProcessor] iteration input', [
            'pending_items_count' => count($pendingItems),
            'processed_chunks_count' => $processedChunksCount,
            'total_document_chunks' => $totalDocumentChunks,
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
                'text' => \SimoneBianco\LaravelRagChunks\Support\TextSanitizer::sanitizeForJson($item->text),
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
            ->withProcessedChunksCount($processedChunksCount)
            ->withTotalDocumentChunks($totalDocumentChunks)
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

        $buffer = $this->buildBufferFromAgentChunks($chunksResponse, $agentOptions, $currentIndex);

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

    /**
     * @param array<int, array<string, mixed>> $chunksResponse
     * @param array<string, mixed> $agentOptions
     * @param array<int, string> $currentIndex
     * @return array<int, array<string, mixed>>
     */
    protected function buildBufferFromAgentChunks(array $chunksResponse, array $agentOptions, array &$currentIndex): array
    {
        $buffer = [];

        foreach ($chunksResponse as $aiData) {
            if (!is_array($aiData)) {
                continue;
            }

            $content = trim((string) ($aiData['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $tags = is_array($aiData['tags'] ?? null) ? array_values($aiData['tags']) : [];
            $questions = is_array($aiData['questions'] ?? null) ? array_values($aiData['questions']) : [];
            $chapter = null;

            if (!empty($agentOptions['create_index'])) {
                $chapterTitle = $this->normalizeChapterTitle((string) ($aiData['chapter_title'] ?? ''));
                if ($chapterTitle !== '') {
                    $chapter = $this->toChapterAlias($chapterTitle);
                    if (is_string($chapter) && $chapter !== '') {
                        $this->addCurrentIndexTitle($currentIndex, $chapterTitle);
                    } else {
                        $chapter = null;
                    }
                }
            }

            $figurePath = !empty($aiData['figure_path']) ? (string) $aiData['figure_path'] : null;

            $deterministicTags = null;
            foreach (array_keys($agentOptions['tags_by_type'] ?? []) as $type) {
                $fieldKey = "tags_$type";
                $tagValues = $aiData[$fieldKey] ?? null;
                if (is_array($tagValues) && !empty($tagValues)) {
                    $deterministicTags[$type] = array_values($tagValues);
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

        return $buffer;
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

    protected function countTotalValidInputChunks(string $relativeSourcePath): int
    {
        $readStream = $this->fileService->readStream($relativeSourcePath);
        $count = 0;

        try {
            while (($line = fgets($readStream)) !== false) {
                if (trim($line) === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if (!is_array($decoded)) {
                    continue;
                }

                $count++;
            }
        } finally {
            $this->fileService->closeStreams($readStream, null);
        }

        return $count;
    }

    /**
     * @return array<int, array{page_number:int,data_url:string}>
     */
    protected function collectPdfPageImagesPayload(string $relativeDirPath): array
    {
        $outputJsonPath = "$relativeDirPath/output.json";
        if (!$this->fileService->exists($outputJsonPath)) {
            Log::channel('document-queue')->warning('[PostProcessor] parse_by_image output.json not found', [
                'output_json_path' => $outputJsonPath,
            ]);
            return [];
        }

        $decoded = json_decode((string) $this->fileService->get($outputJsonPath), true);
        if (!is_array($decoded)) {
            return [];
        }

        $pages = is_array($decoded['pages'] ?? null) ? $decoded['pages'] : [];
        if (empty($pages)) {
            Log::channel('document-queue')->warning('[PostProcessor] parse_by_image pages payload empty', [
                'output_json_path' => $outputJsonPath,
            ]);
            return [];
        }

        $allFiles = $this->fileService->allFiles($relativeDirPath);
        $imagesByBaseName = [];
        foreach ($allFiles as $file) {
            $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                continue;
            }

            $imagesByBaseName[strtolower(basename($file))] = $file;
        }

        $resolved = [];
        foreach ($pages as $index => $page) {
            if (!is_array($page)) {
                continue;
            }

            $candidatePath = $this->resolvePageImagePath($page, $relativeDirPath, $imagesByBaseName);
            if ($candidatePath === null || !$this->fileService->exists($candidatePath)) {
                continue;
            }

            $binary = $this->fileService->get($candidatePath);
            if (!is_string($binary) || $binary === '') {
                continue;
            }

            $mime = $this->guessImageMimeType($candidatePath);
            $resolved[] = [
                'page_number' => $index + 1,
                'data_url' => 'data:' . $mime . ';base64,' . base64_encode($binary),
            ];
        }

        Log::channel('document-queue')->info('[PostProcessor] parse_by_image pages resolved', [
            'relative_dir_path' => $relativeDirPath,
            'pages_total' => count($pages),
            'pages_with_resolved_images' => count($resolved),
            'pages_missing_images' => max(0, count($pages) - count($resolved)),
        ]);

        return $resolved;
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, string> $imagesByBaseName
     */
    protected function resolvePageImagePath(array $page, string $relativeDirPath, array $imagesByBaseName): ?string
    {
        $candidates = [
            $page['image'] ?? null,
            $page['image_path'] ?? null,
            $page['page_image'] ?? null,
            $page['rendered_image'] ?? null,
            $page['preview_image'] ?? null,
            is_array($page['metadata'] ?? null) ? ($page['metadata']['image'] ?? null) : null,
            is_array($page['metadata'] ?? null) ? ($page['metadata']['image_path'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $normalized = trim(str_replace('\\', '/', $candidate));
            if ($normalized === '') {
                continue;
            }

            if ($this->fileService->exists($normalized)) {
                return $normalized;
            }

            $fromRelativeDir = rtrim($relativeDirPath, '/\\') . '/' . ltrim($normalized, '/\\');
            if ($this->fileService->exists($fromRelativeDir)) {
                return $fromRelativeDir;
            }

            $byBaseName = $imagesByBaseName[strtolower(basename($normalized))] ?? null;
            if (is_string($byBaseName) && $byBaseName !== '') {
                return $byBaseName;
            }
        }

        return null;
    }

    protected function guessImageMimeType(string $relativePath): string
    {
        $ext = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    protected function countMeaningfulCharacters(string $text): int
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        return mb_strlen($normalized);
    }

    protected function extractRawPdfPageText(string $sourcePdfRelativePath, int $pageNumber): string
    {
        $pdftotext = (string) config('rag_chunks.agents.image_postprocessor.pdftotext_binary', 'pdftotext');
        $absolutePdfPath = $this->fileService->getAbsolutePath($sourcePdfRelativePath);

        $process = new Process([
            $pdftotext,
            '-f',
            (string) $pageNumber,
            '-l',
            (string) $pageNumber,
            '-layout',
            '-enc',
            'UTF-8',
            $absolutePdfPath,
            '-',
        ]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            return '';
        }

        return trim($process->getOutput());
    }

    protected function extractDeepCleanPdfPageText(string $sourcePdfRelativePath, int $pageNumber): string
    {
        $pdftocairo = (string) config('rag_chunks.agents.image_postprocessor.pdftocairo_binary', 'pdftocairo');
        $absolutePdfPath = $this->fileService->getAbsolutePath($sourcePdfRelativePath);

        $tempDir = $this->fileService->generateTempDirPath('parse-by-image-page-pdf-' . Str::uuid()->toString());
        $this->fileService->createDirectoryIfNotExists($tempDir);

        $prefixRelativePath = rtrim($tempDir, '/\\') . '/page';
        $prefixAbsolutePath = $this->fileService->getAbsolutePath($prefixRelativePath);

        $renderProcess = new Process([
            $pdftocairo,
            '-pdf',
            '-f',
            (string) $pageNumber,
            '-l',
            (string) $pageNumber,
            $absolutePdfPath,
            $prefixAbsolutePath,
        ]);
        $renderProcess->setTimeout(120);
        $renderProcess->run();

        if (!$renderProcess->isSuccessful()) {
            return '';
        }

        $singlePagePdf = null;
        foreach ($this->fileService->allFiles($tempDir) as $file) {
            if (strtolower((string) pathinfo($file, PATHINFO_EXTENSION)) === 'pdf') {
                $singlePagePdf = $file;
                break;
            }
        }

        if ($singlePagePdf === null || !$this->fileService->exists($singlePagePdf)) {
            return '';
        }

        $pdfContent = $this->fileService->get($singlePagePdf);
        if (!is_string($pdfContent) || $pdfContent === '') {
            return '';
        }

        $apiBase = rtrim((string) config('rag_chunks.embedders.multiembedder.base_url', ''), '/');
        if (!preg_match('#^https?://#i', $apiBase)) {
            return '';
        }

        $token = (string) config('rag_chunks.embedders.multiembedder.api_key', '');

        $request = Http::timeout(180)
            ->acceptJson()
            ->when($token !== '', fn ($pendingRequest) => $pendingRequest->withToken($token));

        $response = $request->post("{$apiBase}/clean_pdf_text", [
            'pdf_base64' => base64_encode($pdfContent),
            'enforce_readable' => true,
            'ocr_mode' => 'all_pages',
        ]);

        if (!$response->successful()) {
            return '';
        }

        return trim((string) $response->json('text', ''));
    }

    protected function extractPageTextWithFallback(string $sourcePdfRelativePath, int $pageNumber): string
    {
        $rawText = $this->extractRawPdfPageText($sourcePdfRelativePath, $pageNumber);
        if ($this->countMeaningfulCharacters($rawText) >= self::MIN_CHARACTERS_FOR_PAGE_PROCESSING) {
            Log::channel('document-queue')->debug('[PostProcessor] page text extraction gate passed with raw extraction', [
                'page_number' => $pageNumber,
                'characters' => $this->countMeaningfulCharacters($rawText),
            ]);

            return $rawText;
        }

        $deepCleanText = $this->extractDeepCleanPdfPageText($sourcePdfRelativePath, $pageNumber);
        Log::channel('document-queue')->debug('[PostProcessor] page text extraction fallback deep-clean evaluated', [
            'page_number' => $pageNumber,
            'raw_characters' => $this->countMeaningfulCharacters($rawText),
            'deep_clean_characters' => $this->countMeaningfulCharacters($deepCleanText),
        ]);

        return $deepCleanText !== '' ? $deepCleanText : $rawText;
    }

    /**
     * @return array<int, array{page_number:int,relative_image_path:string}>
     */
    protected function renderPdfPagesToImages(string $sourcePdfRelativePath): array
    {
        if (!$this->fileService->exists($sourcePdfRelativePath)) {
            throw new RuntimeException("Source PDF does not exist: {$sourcePdfRelativePath}");
        }

        $pdftoppm = (string) config('rag_chunks.agents.image_postprocessor.pdftoppm_binary', 'pdftoppm');
        $dpi = max(72, (int) config('rag_chunks.agents.image_postprocessor.pdf_render_dpi', 200));
        $renderTimeoutSeconds = (int) config('rag_chunks.agents.image_postprocessor.pdf_render_timeout_seconds', 1800);

        $renderDir = $this->fileService->generateTempDirPath('parse-by-image-pages-' . Str::uuid()->toString());
        $this->fileService->createDirectoryIfNotExists($renderDir);

        $absolutePdfPath = $this->fileService->getAbsolutePath($sourcePdfRelativePath);
        $prefixRelativePath = rtrim($renderDir, '/\\') . '/page';
        $prefixAbsolutePath = $this->fileService->getAbsolutePath($prefixRelativePath);

        $process = new Process([
            $pdftoppm,
            '-png',
            '-r',
            (string) $dpi,
            $absolutePdfPath,
            $prefixAbsolutePath,
        ]);
        $process->setTimeout($renderTimeoutSeconds > 0 ? $renderTimeoutSeconds : null);

        Log::channel('document-queue')->info('[PostProcessor] starting pdftoppm render', [
            'source_pdf_path' => $sourcePdfRelativePath,
            'pdftoppm_binary' => $pdftoppm,
            'dpi' => $dpi,
            'timeout_seconds' => $renderTimeoutSeconds,
        ]);

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            Log::channel('document-queue')->error('[PostProcessor] pdftoppm render timeout', [
                'source_pdf_path' => $sourcePdfRelativePath,
                'pdftoppm_binary' => $pdftoppm,
                'dpi' => $dpi,
                'timeout_seconds' => $renderTimeoutSeconds,
                'message' => $exception->getMessage(),
            ]);

            // Mirror on default stack for easier operational visibility.
            Log::error('[PostProcessor] pdftoppm render timeout', [
                'source_pdf_path' => $sourcePdfRelativePath,
                'pdftoppm_binary' => $pdftoppm,
                'dpi' => $dpi,
                'timeout_seconds' => $renderTimeoutSeconds,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException('pdftoppm failed: ' . $process->getErrorOutput());
        }

        $allFiles = $this->fileService->allFiles($renderDir);
        $images = [];
        foreach ($allFiles as $file) {
            $basename = strtolower((string) basename($file));
            if (!preg_match('/^page-(\d+)\.(png|jpg|jpeg)$/', $basename, $matches)) {
                continue;
            }

            $images[] = [
                'page_number' => (int) $matches[1],
                'relative_image_path' => $file,
            ];
        }

        usort($images, static fn (array $a, array $b): int => $a['page_number'] <=> $b['page_number']);

        if (empty($images)) {
            throw new RuntimeException('No page images generated by pdftoppm.');
        }

        Log::channel('document-queue')->info('[PostProcessor] rendered PDF pages to images', [
            'source_pdf_path' => $sourcePdfRelativePath,
            'render_dir' => $renderDir,
            'pages_count' => count($images),
            'dpi' => $dpi,
            'timeout_seconds' => $renderTimeoutSeconds,
        ]);

        return $images;
    }

    protected function imageDataUrlFromRelativePath(string $relativeImagePath): string
    {
        $binary = (string) $this->fileService->get($relativeImagePath);
        if ($binary === '') {
            throw new RuntimeException("Rendered page image is empty: {$relativeImagePath}");
        }

        $mime = $this->guessImageMimeType($relativeImagePath);
        return 'data:' . $mime . ';base64,' . base64_encode($binary);
    }

    /**
     * @param array{page_number:int,data_url:string} $pageImage
     * @param array<string, mixed> $agentOptions
     * @param array<int, string> $currentIndex
     * @return array{buffer:array<int,array<string,mixed>>,active_context_for_next_chunking:string,useful_info_for_next_chunking:string}
     */
    protected function runImageAgentAndPrepareBuffer(
        array $pageImage,
        ?string $documentContext,
        array $agentOptions,
        ?string $activeContextFromPreviousChunking,
        ?string $usefulInfoFromPreviousChunking,
        array &$currentIndex,
        int $processedChunksCount,
        int $totalDocumentChunks,
    ): array {
        $imageAgent = new ImagePostProcessingAgent(Str::random(), $agentOptions);
        $agentResponse = $imageAgent
            ->withDocumentContext($documentContext)
            ->withPageImageDataUrl($pageImage['data_url'])
            ->withPageNumber($pageImage['page_number'])
            ->withPreferredChunkLength($agentOptions['preferred_chunk_length'] ?? 600)
            ->withExtraInstructions($agentOptions['extra_instructions'] ?? null)
            ->withTagsByType($agentOptions['tags_by_type'] ?? [])
            ->withCreateIndex(!empty($agentOptions['create_index']))
            ->withCurrentIndex($currentIndex)
            ->withActiveContextFromPreviousChunking($activeContextFromPreviousChunking)
            ->withUsefulInfoFromPreviousChunking($usefulInfoFromPreviousChunking)
            ->withProcessedChunksCount($processedChunksCount)
            ->withTotalDocumentChunks($totalDocumentChunks)
            ->respond();

        $chunksResponse = is_array($agentResponse['chunks'] ?? null) ? $agentResponse['chunks'] : [];
        $activeContextForNextChunking = '';
        if (is_array($agentResponse) && is_string($agentResponse['active_context_for_next_chunking'] ?? null)) {
            $activeContextForNextChunking = $this->normalizeActiveContext($agentResponse['active_context_for_next_chunking']);
        }

        $usefulInfoForNextChunking = '';
        if (is_array($agentResponse) && is_string($agentResponse['useful_info_for_next_chunking'] ?? null)) {
            $usefulInfoForNextChunking = trim($agentResponse['useful_info_for_next_chunking']);
        }

        return [
            'buffer' => $this->buildBufferFromAgentChunks($chunksResponse, $agentOptions, $currentIndex),
            'active_context_for_next_chunking' => $activeContextForNextChunking,
            'useful_info_for_next_chunking' => $usefulInfoForNextChunking,
        ];
    }

    /**
     * @throws InvalidEmbeddingDriverException
     * @throws PostProcessingException
     */
    protected function postProcessByImages(
        string $relativeSourcePath,
        string $relativeOutputPath,
        ?string $documentContext,
        array $agentOptions = [],
        int $startFromInputLine = 0,
        ?callable $onBatchComplete = null,
    ): void {
        $embedder = EmbeddingFactory::make();
        $relativeDirPath = pathinfo($relativeSourcePath, PATHINFO_DIRNAME);

        Log::channel('document-queue')->info('[PostProcessor] parse_by_image mode enabled', [
            'relative_source_path' => $relativeSourcePath,
            'relative_output_path' => $relativeOutputPath,
            'relative_dir_path' => $relativeDirPath,
            'start_from_input_line' => $startFromInputLine,
        ]);

        $pageImages = $this->collectPdfPageImagesPayload($relativeDirPath);
        if (empty($pageImages)) {
            throw new PostProcessingException('Image post-processing requested but no PDF page images were found in parser output directory.');
        }

        $writeStream = $this->fileService->writeStream($relativeOutputPath);
        try {
            if ($startFromInputLine > 0) {
                $this->streamService->goToEnd($writeStream);
            }

            $activeContextForNextChunking = null;
            $usefulInfoForNextChunking = null;
            $currentIndex = [];
            $processedCount = 0;
            $totalPages = count($pageImages);

            foreach ($pageImages as $index => $pageImage) {
                $currentLine = $index + 1;
                if ($currentLine <= $startFromInputLine) {
                    continue;
                }

                Log::channel('document-queue')->debug('[PostProcessor] parse_by_image processing page', [
                    'page_number' => $pageImage['page_number'],
                    'progress_line' => $currentLine,
                    'total_pages' => $totalPages,
                ]);

                $result = $this->runImageAgentAndPrepareBuffer(
                    $pageImage,
                    $documentContext,
                    $agentOptions,
                    $activeContextForNextChunking,
                    $usefulInfoForNextChunking,
                    $currentIndex,
                    $processedCount,
                    $totalPages,
                );

                $this->processPostProcessingBuffer($result['buffer'], $writeStream, $embedder);
                $activeContextForNextChunking = $result['active_context_for_next_chunking'] ?? null;
                $usefulInfoForNextChunking = $result['useful_info_for_next_chunking'] ?? null;
                $processedCount++;

                Log::channel('document-queue')->debug('[PostProcessor] parse_by_image page completed', [
                    'page_number' => $pageImage['page_number'],
                    'chunks_emitted' => count($result['buffer'] ?? []),
                    'processed_pages' => $processedCount,
                    'total_pages' => $totalPages,
                ]);

                if ($onBatchComplete) {
                    $onBatchComplete($currentLine);
                }
            }

            Log::channel('document-queue')->info('[PostProcessor] parse_by_image mode completed', [
                'processed_pages' => $processedCount,
                'total_pages' => $totalPages,
            ]);
        } finally {
            $this->fileService->closeStreams(null, $writeStream);
        }
    }

    /**
     * @throws InvalidEmbeddingDriverException
     * @throws PostProcessingException
     */
    public function postProcessByImage(
        string $sourcePdfRelativePath,
        string $relativeOutputPath,
        ?string $documentContext,
        array $agentOptions = [],
        int $startFromInputLine = 0,
        ?callable $onBatchComplete = null,
    ): void {
        $embedder = EmbeddingFactory::make();
        $writeStream = null;

        try {
            $pageImages = $this->renderPdfPagesToImages($sourcePdfRelativePath);
            $writeStream = $this->fileService->writeStream($relativeOutputPath);

            if ($startFromInputLine > 0) {
                $this->streamService->goToEnd($writeStream);
            }

            $activeContextForNextChunking = null;
            $usefulInfoForNextChunking = null;
            $currentIndex = [];
            $processedCount = 0;
            $totalPages = count($pageImages);

            foreach ($pageImages as $index => $pageImage) {
                $currentLine = $index + 1;
                if ($currentLine <= $startFromInputLine) {
                    continue;
                }

                $pageText = $this->extractPageTextWithFallback($sourcePdfRelativePath, $pageImage['page_number']);
                $characters = $this->countMeaningfulCharacters($pageText);
                if ($characters < self::MIN_CHARACTERS_FOR_PAGE_PROCESSING) {
                    Log::channel('document-queue')->info('[PostProcessor] skipping page in parse_by_image due to low extracted text volume', [
                        'page_number' => $pageImage['page_number'],
                        'characters' => $characters,
                        'threshold' => self::MIN_CHARACTERS_FOR_PAGE_PROCESSING,
                    ]);

                    if ($onBatchComplete) {
                        $onBatchComplete($currentLine);
                    }

                    continue;
                }

                $result = $this->runImageAgentAndPrepareBuffer(
                    [
                        'page_number' => $pageImage['page_number'],
                        'data_url' => $this->imageDataUrlFromRelativePath($pageImage['relative_image_path']),
                    ],
                    $documentContext,
                    $agentOptions,
                    $activeContextForNextChunking,
                    $usefulInfoForNextChunking,
                    $currentIndex,
                    $processedCount,
                    $totalPages,
                );

                $this->processPostProcessingBuffer($result['buffer'], $writeStream, $embedder);
                $activeContextForNextChunking = $result['active_context_for_next_chunking'] ?? null;
                $usefulInfoForNextChunking = $result['useful_info_for_next_chunking'] ?? null;
                $processedCount++;

                Log::channel('document-queue')->debug('[PostProcessor] parse_by_image page processed', [
                    'page_number' => $pageImage['page_number'],
                    'processed_pages' => $processedCount,
                    'total_pages' => $totalPages,
                ]);

                if ($onBatchComplete) {
                    $onBatchComplete($currentLine);
                }
            }

            Log::channel('document-queue')->info('[PostProcessor] parse_by_image completed (direct PDF rendering)', [
                'source_pdf_path' => $sourcePdfRelativePath,
                'processed_pages' => $processedCount,
                'total_pages' => $totalPages,
            ]);
        } catch (Throwable $exception) {
            throw new PostProcessingException(
                "Error during image post-processing: {$exception->getMessage()}",
                0,
                $exception,
                get_class($exception),
                $sourcePdfRelativePath,
                0,
                '',
                $this->isRetryableImagePostProcessingFailure($exception)
            );
        } finally {
            $this->fileService->closeStreams(null, $writeStream);
        }
    }

    protected function isRetryableImagePostProcessingFailure(Throwable $exception): bool
    {
        if ($exception instanceof ProcessTimedOutException) {
            return true;
        }

        $message = Str::lower($exception->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'curl error')
            || str_contains($message, 'syntax error')
            || str_contains($message, 'invalid json')
            || str_contains($message, 'json syntax error')
            || str_contains($message, 'could not parse the json body')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'rate-limit')
            || str_contains($message, 'server error')
            || str_contains($message, '500')
            || str_contains($message, '503');
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
        if (!empty($agentOptions['parse_by_image'])) {
            Log::channel('document-queue')->info('[PostProcessor] routing to image post-processing branch', [
                'relative_source_path' => $relativeSourcePath,
                'relative_output_path' => $relativeOutputPath,
            ]);

            $this->postProcessByImages(
                $relativeSourcePath,
                $relativeOutputPath,
                $documentContext,
                $agentOptions,
                $startFromInputLine,
                $onBatchComplete,
            );

            return;
        }

        $embedder = EmbeddingFactory::make();

        $currentInputLine = 0;
        $lastProcessedLineContent = '';

        try {
            $relativeDirPath = pathinfo($relativeSourcePath, PATHINFO_DIRNAME);
            $readStream = $this->fileService->readStream($relativeSourcePath);
            $writeStream = $this->fileService->writeStream($relativeOutputPath);
            $totalDocumentChunks = $this->countTotalValidInputChunks($relativeSourcePath);

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
            $processedChunksCount = 0; // cumulative count of already-processed input chunks

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
                        $previousTailContext, $suffixContext, $activeContextForNextChunking, $usefulInfoForNextChunking, $currentIndex,
                        $processedChunksCount,
                        $totalDocumentChunks
                    );
                    $this->processPostProcessingBuffer($agentProcessingResult['buffer'], $writeStream, $embedder);
                    $activeContextForNextChunking = $agentProcessingResult['active_context_for_next_chunking'] ?? null;
                    $usefulInfoForNextChunking = $agentProcessingResult['useful_info_for_next_chunking'] ?? null;

                    // Increment cumulative count by the number of input chunks processed in this batch
                    $processedChunksCount += count($pendingBatch);

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
                    $previousTailContext, null, $activeContextForNextChunking, $usefulInfoForNextChunking, $currentIndex,
                    $processedChunksCount,
                    $totalDocumentChunks
                );
                $this->processPostProcessingBuffer($agentProcessingResult['buffer'], $writeStream, $embedder);
                // Increment cumulative count for the final batch as well
                $processedChunksCount += count($pendingBatch);
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
            $message = $exception->getMessage();
            $lowerMessage = Str::lower($message);
            $isRetryable = ($exception instanceof \RuntimeException && $prev instanceof \TypeError)
                || $prev instanceof ConnectException
                || $prev instanceof ServerException
                || $exception instanceof \OpenAI\Exceptions\UnserializableResponse
                || $exception instanceof \OpenAI\Exceptions\RateLimitException
                || $exception instanceof \JsonException
                || str_contains($message, 'Syntax error')
                || str_contains($message, 'timed out')
                || str_contains($message, 'Connection refused')
                || str_contains($message, 'cURL error')
                || str_contains($message, 'We could not parse the JSON body of your request')
                || str_contains($lowerMessage, 'rate limit')
                || str_contains($lowerMessage, 'rate-limit')
                || str_contains($lowerMessage, '429')
                || str_contains($lowerMessage, 'throttle')
                || str_contains($lowerMessage, 'too many requests');

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
