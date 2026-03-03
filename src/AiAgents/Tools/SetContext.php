<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Tool;
use Psr\Log\LoggerInterface;

class SetContext extends Tool
{
    public function __construct(
        protected string $contextKey,
        ?string $name = 'set_context',
        ?string $description = 'Set the current document context (e.g., Chapter, Section, main subject) to keep track of location for the next agent iteration.',
    ) {
        parent::__construct($name, $description);
        Log::channel('document-queue')->debug("CONTEXT KEY: $this->contextKey");
    }

    protected function setContext(string $value): self
    {
        Cache::set($this->contextKey, $value, 300);

        return $this;
    }

    protected function getContext(): self
    {
        Cache::get($this->contextKey, '');

        return $this;
    }

    protected function logger(): LoggerInterface
    {
        return Log::channel(config('logging.default', 'stack'));
    }

    public function getProperties(): array
    {
        return [
            'chapter' => [
                'type' => 'string',
                'description' => 'The brief, hierarchical context data to save (e.g., "Chapter 3: Core Concepts - Subject: Routing"). Keep it concise.',
            ],
            'cache_memory' => [
                'type' => 'array',
                'description' => 'A list of relevant short cache memories, keep only the ones that may be relevant next; keep not more than 5-10 memories',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'content' => [
                            'type' => 'string',
                            'description' => 'Memory content'
                        ],
                        'was_relevant' => [
                            'type' => 'string',
                            'description' => 'If this memory was relevant in this iteration, then set to yes; if not, set to no',
                            'enum' => ['yes', 'no']
                        ]
                    ],
                    'required' => ['content', 'was_relevant']
                ]
            ]
        ];
    }

    protected array $required = ['chapter', 'cache_memory'];

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    protected function handle(array|DataModel $input): mixed
    {
        $data = $input instanceof DataModel ? $input->toArray() : $input;
        $textToSave = json_encode($data);

        $this->setContext($textToSave);

        Log::channel('document-queue')->debug("FULL CONTEXT for $this->contextKey", [
            'added_hidden' => $textToSave,
            'full' => $this->getContext()
        ]);

        $this->logger()->debug('Context set by Agent', ['context' => $textToSave]);

        return [
            'status' => 'Context saved'
        ];
    }
}
