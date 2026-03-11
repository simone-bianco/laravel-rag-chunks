<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Tool;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\Enums\RelationType;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Project;

class GetChunksByAliases extends Tool
{
    public function __construct(
        protected Project $project,
        protected ?Document $document = null,
        ?string $name = 'get_chunks_by_aliases',
        ?string $description = 'Retrieve specific chunks by UUIDs with chapter, neighbors and relations.'
    ) {
        parent::__construct($name, $description);
    }

    public function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    protected array $required = ['chunksAliases'];

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    public function getProperties(): array
    {
        return [
            'chunksAliases' => [
                'type' => 'array',
                'description' => 'Chunk UUIDs to fetch directly. Use this when you already know exact chunk IDs and need neighbors/relations.',
                'items' => [
                    'type' => 'string',
                ],
            ],
        ];
    }

    protected function handle(array|DataModel $input): mixed
    {
        $data = is_array($input) ? $input : $input->toArray();

        $aliases = collect($data['chunksAliases'] ?? [])
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn ($v) => trim($v))
            ->unique()
            ->values();

        if ($aliases->isEmpty()) {
            return ['chunks' => [], 'missing_aliases' => []];
        }

        $chunks = Chunk::query()
            ->whereIn('id', $aliases->all())
            ->whereHas('document.project', function (Builder $q) {
                $q->where('alias', $this->project->alias);
            })
            ->when($this->document, function (Builder $q) {
                $q->whereHas('document', fn (Builder $sq) => $sq->where('alias', $this->document->alias));
            })
            ->with([
                'dedupMedia',
                'outgoingRelations.to_entity',
                'incomingRelations' => function ($q) {
                    $q->where('type', RelationType::BIDIRECTIONAL->value)->with('from_entity');
                },
            ])
            ->withNeighborSnippets()
            ->get();

        $mapped = $chunks->mapWithKeys(fn (Chunk $chunk) => [
            $chunk->id => ChunkMapper::loadAndMap($chunk),
        ]);

        $missing = $aliases->diff($mapped->keys())->values()->all();

        return [
            'chunks' => $mapped->toArray(),
            'missing_aliases' => $missing,
        ];
    }
}
