<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use Illuminate\Support\Str;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Tool;
use SimoneBianco\LaravelAiAgents\Concerns\ExposesEditableParameters;

class SaveResponseData extends Tool
{
    use ExposesEditableParameters;

    public function editableParameters(): array
    {
        return [
            ['name' => 'relevant_chunks', 'type' => 'array', 'description' => 'Relevant chunk UUIDs', 'default' => null, 'overridable' => false, 'variable_bindable' => false, 'toggleable' => false, 'default_enabled' => true],
            ['name' => 'relevant_images', 'type' => 'array', 'description' => 'Relevant image objects', 'default' => null, 'overridable' => false, 'variable_bindable' => false, 'toggleable' => true, 'default_enabled' => true],
            ['name' => 'proposed_connections', 'type' => 'array', 'description' => 'Proposed inter-chunk connections', 'default' => null, 'overridable' => false, 'variable_bindable' => false, 'toggleable' => true, 'default_enabled' => true],
        ];
    }

    protected static ?array $lastResponseData = null;

    public function __construct(
        ?string $name = 'save_response_data',
        ?string $description = 'Save relevant chunks and proposed connections found during search. Call this AFTER searching and BEFORE writing your response.'
    ) {
        parent::__construct($name, $description);
    }

    public function getProperties(): array
    {
        return [
            'relevant_chunks' => [
                'type' => 'array',
                'description' => 'Array of relevant chunk UUIDs found during search',
                'items' => ['type' => 'string'],
            ],
            'relevant_images' => [
                'type' => 'array',
                'description' => 'Array of relevant images from the search results',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string'],
                        'content' => ['type' => 'string', 'description' => 'Brief description of the image'],
                    ],
                    'required' => ['url', 'content'],
                ],
            ],
            'proposed_connections' => [
                'type' => 'array',
                'description' => 'Proposed connections between chunks or documents based on the search results',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'source_entity_type' => ['type' => 'string', 'enum' => ['chunk', 'document']],
                        'source_entity_alias' => ['type' => 'string'],
                        'destination_entity_type' => ['type' => 'string', 'enum' => ['chunk', 'document']],
                        'destination_entity_alias' => ['type' => 'string'],
                        'relation_type' => ['type' => 'string', 'enum' => ['bidirectional', 'unidirectional']],
                        'relation_name' => ['type' => 'string'],
                        'relation_description' => ['type' => 'string'],
                    ],
                    'required' => [
                        'source_entity_type', 'source_entity_alias',
                        'destination_entity_type', 'destination_entity_alias',
                        'relation_type', 'relation_name', 'relation_description',
                    ],
                ],
            ],
        ];
    }

    protected array $required = ['relevant_chunks', 'proposed_connections'];

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    protected function handle(array|DataModel $input): mixed
    {
        $data = ! is_array($input) ? $input->toArray() : $input;

        $connections = $data['proposed_connections'] ?? [];
        foreach ($connections as &$conn) {
            $conn['id'] = (string) Str::uuid();
            $conn['status'] = 'pending';
        }

        static::$lastResponseData = [
            'relevant_chunks'    => $data['relevant_chunks'] ?? [],
            'relevant_images'    => $data['relevant_images'] ?? [],
            'proposed_connections' => $connections,
            'applied_connections'  => [],
        ];

        return ['status' => 'saved', 'chunks_count' => count(static::$lastResponseData['relevant_chunks'])];
    }

    public static function getLastResponseData(): ?array
    {
        return static::$lastResponseData;
    }

    public static function resetLastResponseData(): void
    {
        static::$lastResponseData = null;
    }
}
