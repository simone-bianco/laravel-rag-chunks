<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

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
    }

    public function logger(): LoggerInterface
    {
        return Log::channel(config('logging.default', 'stack'));
    }

    public function getProperties(): array
    {
        return [
            'text' => [
                'type' => 'string',
                'description' => 'The brief, hierarchical context data to save (e.g., "Chapter 3: Spells - Subject: Wizard"). Keep it concise.',
            ],
        ];
    }

    protected array $required = ['text'];

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    protected function handle(array|DataModel $input): mixed
    {
        $textToSave = $input['text'] ?? 'None';

        Context::addHidden($this->contextKey, $textToSave);

        $this->logger()->debug('Context set by Agent', ['context' => $textToSave]);

        return [
            'status' => 'Context successfully updated for the next iteration',
            'saved_context' => $textToSave,
        ];
    }
}
