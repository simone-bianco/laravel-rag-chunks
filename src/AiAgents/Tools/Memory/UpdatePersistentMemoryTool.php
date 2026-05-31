<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools\Memory;

use App\Models\PersistentMemory;
use Illuminate\Support\Facades\Log;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Tool;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\Concerns\NormalizesChunkIds;

class UpdatePersistentMemoryTool extends Tool
{
    use NormalizesChunkIds;

    protected ?int $maxWords = null;

    public function __construct(
        protected ?string $scopeId = null,
        ?int $maxWords = null,
        ?string $name = 'update_persistent_memory',
        ?string $description = 'Save reusable search strategies (caveman style, one tip per line, ; separated). Focus on parameter patterns (tags, textSearch, documentSearch). NEVER save per-query results. Only reference docs if 500+ chunks or multiple similar docs.'
    ) {
        $this->maxWords = $maxWords;

        parent::__construct($name, $description);
    }

    public function withMaxWordsLength(int $maxWords): static
    {
        $this->maxWords = $maxWords;

        return $this;
    }

    public function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    public function getProperties(): array
    {
        return [
            'persistentMemory' => [
                'type'        => 'object',
                'description' => 'Update Information',
                'properties' => [
                    'mode' => [
                        'type' => 'string',
                        'enum' => ['append', 'replace'],
                        'description' => 'Update information strategy',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'Content of the memory',
                    ],
                ],
                'required' => ['content'],
                'additionalProperties' => false,
            ],
        ];
    }

    protected array $required = ['persistentMemory'];

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    protected function handle(array|DataModel $input): mixed
    {
        $data   = is_array($input) ? $input : $input->toArray();
        $schema = is_array($data) ? $data : [];

        $persistentMemory = $schema['persistentMemory'] ?? [];
        if (! is_array($persistentMemory) || empty($persistentMemory)) {
            $this->logger()->warning('[UpdatePersistentMemoryTool] Empty update memory request');

            return ['error' => 'persistentMemory cannot be empty'];
        }

        $mode    = is_string($persistentMemory['mode'] ?? null) ? $persistentMemory['mode'] : 'replace';
        $content = is_string($persistentMemory['content'] ?? null) ? trim($persistentMemory['content']) : '';

        if ($content === '') {
            $this->logger()->warning('[UpdatePersistentMemoryTool] Empty content');

            return ['error' => 'content cannot be empty'];
        }

        $truncated = false;
        if ($this->maxWords !== null && $this->maxWords > 0) {
            $words = str_word_count($content, 2);
            if (count($words) > $this->maxWords) {
                $truncatedWords = array_slice($words, 0, $this->maxWords, true);
                $lastKey = array_key_last($truncatedWords);
                $content = substr($content, 0, $lastKey + strlen((string) $lastKey))
                    . '...[truncated]';
                $truncated = true;
            }
        }

        $memoryKey = $this->scopeId;

        if ($memoryKey === null || trim($memoryKey) === '') {
            $this->logger()->warning('[UpdatePersistentMemoryTool] No memory key set');

            return ['error' => 'scopeId not configured'];
        }

        $existing = PersistentMemory::query()->where('memory_key', $memoryKey)->first();

        if ($mode === 'replace' || $existing === null) {
            PersistentMemory::query()->updateOrCreate(
                ['memory_key' => $memoryKey],
                ['content' => $content],
            );

            $this->logger()->info('[UpdatePersistentMemoryTool] Memory replaced', [
                'memory_key' => $memoryKey,
                'word_count' => str_word_count($content),
                'truncated' => $truncated,
                'mode' => $mode,
            ]);

            return [
                'status' => 'ok',
                'memory_key' => $memoryKey,
                'word_count' => str_word_count($content),
                'truncated' => $truncated,
                'mode' => $mode,
            ];
        }

        // Append mode with existing row — single newline, no blank lines between entries
        $existing->content = trim(($existing->content ?? '') . "\n" . $content);
        $existing->save();

        $appendedWordCount = str_word_count($existing->content);
        $this->logger()->info('[UpdatePersistentMemoryTool] Memory appended', [
            'memory_key' => $memoryKey,
            'word_count' => $appendedWordCount,
            'truncated' => $truncated,
            'mode' => 'append',
        ]);

        return [
            'status' => 'ok',
            'memory_key' => $memoryKey,
            'word_count' => $appendedWordCount,
            'truncated' => $truncated,
            'mode' => 'append',
        ];
    }
}
