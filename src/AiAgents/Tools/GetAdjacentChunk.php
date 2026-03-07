<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use Illuminate\Support\Facades\Log;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Tool;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\Models\Chunk;

class GetAdjacentChunk extends Tool
{
    public function __construct(
        ?string $name = 'get_adjacent_chunk',
        ?string $description = 'Get the chunk immediately before or after a given chunk in the same document'
    ) {
        parent::__construct($name, $description);
    }

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
            'direction' => [
                'type' => 'string',
                'enum' => ['previous', 'next'],
                'description' => 'Direction to fetch the chunk: "previous" or "next"'
            ],
        ];
    }

    protected array $required = ['chunkId', 'direction'];

    protected function getDirectionInt(?string $dir = null): int
    {
        if (method_exists($this, 'direction')) {
            return $this->direction();
        }
        return $dir === 'previous' ? -1 : 1;
    }

    protected function handle(array|DataModel $input): mixed
    {
        $data = !is_array($input) ? $input->toArray() : $input;

        $dirStr = $data['direction'] ?? 'next';
        $dirInt = $this->getDirectionInt($dirStr);

        $this->logger()->debug('[Tool] ' . static::class . ' called', ['chunkId' => $data['chunkId'], 'direction' => $dirStr]);

        $current = Chunk::find($data['chunkId']);

        if (! $current) {
            return ['error' => 'Chunk not found'];
        }

        $adjacent = Chunk::query()
            ->where('document_id', $current->document_id)
            ->where('order', $current->order + $dirInt)
            ->withNeighborSnippets()
            ->with('dedupMedia')
            ->first();

        if (! $adjacent) {
            return ['error' => 'No ' . ($dirInt === -1 ? 'previous' : 'next') . ' chunk available'];
        }

        $result = [$adjacent->id => ChunkMapper::loadAndMap($adjacent)];

        $this->logger()->debug('[Tool] ' . static::class . ' returned', ['data' => $result]);

        return $result;
    }
}
