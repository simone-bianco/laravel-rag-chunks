<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
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
        Context::addHidden($this->contextKey, $value);

        return $this;
    }

    protected function getContext(): self
    {
        Context::getHidden($this->contextKey, '');

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
            'brief_context' => [
                'type' => 'string',
                'description' => 'A very brief summary of the context, that could be useful for the next chunks batch'
            ]
        ];
    }

    protected array $required = ['chapter', 'brief_context'];

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
            'status' => 'Context successfully saved.',
            'system_instruction' => 'CRITICAL STOP: The context is saved. DO NOT call any more tools. You MUST IMMEDIATELY generate and return the final JSON array of chunks.'
        ];
    }
}
