<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use Illuminate\Support\Facades\Log;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Tool;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\Enums\RelationType;
use SimoneBianco\LaravelRagChunks\Models\Chunk;

class ConnectChunks extends Tool
{
    public function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    public function __construct(
        ?string $name = 'connect_chunks',
        ?string $description = 'RAG connect chunks'
    ) {
        parent::__construct($name, $description);
    }

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    public function getProperties(): array
    {
        return [
            'fromChunkId' => [
                'type' => 'string',
                'description' => 'Chunk id the relations starts from',
            ],
            'toChunkId' => [
                'type' => 'string',
                'description' => 'Chunk id the relations ends to',
            ],
            'relationName' => [
                'type' => 'string',
                'description' => 'Name of the relation, e.g. "Goblin stats"',
            ],
            'relationDescription' => [
                'type' => 'string',
                'description' => 'Short description of the relation e.g. "Contains: goblins statistics, goblins history, goblins lair"',
            ],
            'relationType' => [
                'type' => 'string',
                'description' => 'Specify if the relation is unidirectional (can be read from the "fromChunk") or if it is bidirectional',
                'enum' => [
                    RelationType::UNIDIRECTIONAL->value,
                    RelationType::BIDIRECTIONAL->value,
                ],
            ],
        ];
    }

    protected array $required = ['fromChunkId', 'toChunkId', 'relationName', 'relationDescription', 'relationType'];

    protected function handle(array|DataModel $input): mixed
    {
        $this->logger()->debug('[Tool] ConnectChunks called', ['data' => $input]);

        $chunks = Chunk::whereIn('id', [$input['fromChunkId'], $input['toChunkId']])->get();
        if ($chunks->count() <= 1) {
            return [
                'error' => 'Two chunks are required',
            ];
        }

        $fromChunk = $chunks->firstWhere('id', $input['fromChunkId']);
        $toChunk = $chunks->firstWhere('id', $input['toChunkId']);

        if ($input['relationType'] === RelationType::UNIDIRECTIONAL) {
            $fromChunk->relateUnidirectionallyTo($toChunk, $input['relationName'], $input['relationDescription']);
        } else {
            $fromChunk->relateBidirectionallyTo($toChunk, $input['relationName'], $input['relationDescription']);
        }

        $this->logger()->debug('[Tool] Chunks connected', ['data' => $fromChunk->relations]);

        return ['status' => 'chunks connected'];
    }
}
