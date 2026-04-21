<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Tool;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelAiAgents\Concerns\ExposesEditableParameters;
use SimoneBianco\LaravelRagChunks\AiAgents\SearchScope;
use SimoneBianco\LaravelRagChunks\Enums\RelationType;
use SimoneBianco\LaravelRagChunks\Enums\SearchScopeType;
use SimoneBianco\LaravelRagChunks\Models\Chunk;

class GetChunksByAliases extends Tool
{
    use ExposesEditableParameters;

    public function editableParameters(): array
    {
        return [
            [
                'name'              => 'chunksAliases',
                'type'              => 'array',
                'description'       => 'Chunk UUIDs to fetch.',
                'default'           => null,
                'overridable'       => false,
                'variable_bindable' => false,
                'toggleable'        => false,
                'default_enabled'   => true,
            ],
        ];
    }

    public function __construct(
        protected SearchScope $scope,
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
                'type'        => 'array',
                'description' => 'Chunk UUIDs to fetch directly. Use this when you already know exact chunk IDs and need neighbors/relations.',
                'items'       => [
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
            ->when($this->scope->type === SearchScopeType::Project, function (Builder $q) {
                $q->whereHas('document.project', fn (Builder $sq) => $sq->where('alias', $this->scope->alias));
            })
            ->when($this->scope->type === SearchScopeType::Document, function (Builder $q) {
                $q->whereHas('document', fn (Builder $sq) => $sq->where('alias', $this->scope->alias));
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
            'chunks'          => $mapped->toArray(),
            'missing_aliases' => $missing,
        ];
    }
}
