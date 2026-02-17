<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use Illuminate\Support\Facades\Log;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Tool;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\Models\Chunk;

abstract class GetAdjacentChunk extends Tool
{
    abstract protected function direction(): int; // -1 for previous, +1 for next

    public function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    public function getProperties(): array
    {
        return [
            'chunkId' => [
                'type' => 'string',
                'description' => 'UUID of the reference chunk',
            ],
        ];
    }

    protected array $required = ['chunkId'];

    protected function handle(array|DataModel $input): mixed
    {
        $data = !is_array($input) ? $input->toArray() : $input;

        $this->logger()->debug('[Tool] ' . static::class . ' called', ['chunkId' => $data['chunkId']]);

        $current = Chunk::find($data['chunkId']);

        if (! $current) {
            return ['error' => 'Chunk not found'];
        }

        $adjacent = Chunk::query()
            ->where('document_id', $current->document_id)
            ->where('order', $current->order + $this->direction())
            ->withNeighborSnippets()
            ->with('dedupMedia')
            ->first();

        if (! $adjacent) {
            return ['error' => 'No ' . ($this->direction() === -1 ? 'previous' : 'next') . ' chunk'];
        }

        $result = [$adjacent->id => ChunkMapper::loadAndMap($adjacent)];

        $this->logger()->debug('[Tool] ' . static::class . ' returned', ['data' => $result]);

        return $result;
    }
}
