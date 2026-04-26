<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools\Memory;

use Illuminate\Support\Facades\Log;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Tool;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\ChunkMapper;
use SimoneBianco\LaravelRagChunks\Enums\RelationType;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Services\SearchResultService;

class SaveSearchesResultsTool extends Tool
{
    public function __construct(
        protected ?string $scopeProjectId = null,
        ?string $name = 'save_searches_results',
        ?string $description = 'Persist final reusable search results. Call once, at the end of a fruitful search session, with one query and its final chunk UUIDs per saved result.'
    ) {
        parent::__construct($name, $description);
    }

    public function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    public function getProperties(): array
    {
        return [
            'searchResults' => [
                'type'        => 'array',
                'description' => 'Final fruitful search-result groups to cache for future reuse. Do not include empty, weak, intermediate, or duplicate result sets.',
                'items'       => [
                    'type'        => 'object',
                    'description' => 'One reusable search result produced at the end of this search session.',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'The reusable query/description for this result set: one concise sentence that explains what the chunks answer.',
                        ],
                        'notes' => [
                            'type' => 'string',
                            'description' => 'Optional operational note for future retrieval (e.g. "Deeply searched in the project, no relevant chunks found").',
                        ],
                        'chunk_ids' => [
                            'type' => 'array',
                            'description' => 'Final relevant chunk UUIDs to save for this query. Include only chunks that are actually useful.',
                            'items'       => [
                                'type'        => 'string',
                                'description' => 'Chunk UUID.',
                            ],
                        ],
                        'relevant_images' => [
                            'type' => 'array',
                            'description' => 'Relevant images connected to this result set, when any were found.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'url' => ['type' => 'string', 'description' => 'Image URL.'],
                                    'content' => ['type' => 'string', 'description' => 'Brief description of what the image shows.'],
                                ],
                                'required' => ['url', 'content'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['query', 'chunk_ids'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    protected array $required = ['searchResults'];

    public function execute(array $input): mixed
    {
        return $this->handle($input);
    }

    protected function handle(array|DataModel $input): mixed
    {
        $data   = is_array($input) ? $input : $input->toArray();
        $schema = is_array($data) ? $data : [];

        $searchResults = $schema['searchResults'] ?? [];
        if (! is_array($searchResults) || empty($searchResults)) {
            $this->logger()->warning('[SaveSearchesResultsTool] Empty save request');

            return ['error' => 'searchResults cannot be empty'];
        }

        $this->logger()->info('[SaveSearchesResultsTool] Save requested', [
            'requested_count' => count($searchResults),
        ]);

        $saved = [];
        $skipped = [];

        foreach (array_values($searchResults) as $index => $item) {
            if (! is_array($item)) {
                $skipped[] = ['index' => $index, 'reason' => 'invalid_item'];
                continue;
            }

            $query = $this->normalizeQuery($item);
            $notes = $this->normalizeNotes($item);
            $chunkIds = $this->normalizeChunkIds($item['chunk_ids'] ?? $item['chunks_uuids'] ?? $item['relevant_chunks'] ?? []);

            if ($query === '') {
                $skipped[] = ['index' => $index, 'reason' => 'empty_query'];
                continue;
            }

            if ($chunkIds === []) {
                $skipped[] = ['index' => $index, 'reason' => 'empty_chunk_ids', 'query' => mb_substr($query, 0, 200)];
                continue;
            }

            $payload = $this->buildResultPayload($chunkIds, $item['relevant_images'] ?? []);
            if (empty($payload['chunk_ids'])) {
                $skipped[] = ['index' => $index, 'reason' => 'no_project_chunks', 'query' => mb_substr($query, 0, 200)];
                continue;
            }

            $projectId = $this->resolveProjectId($payload['chunk_ids']);
            if ($projectId === null) {
                $skipped[] = ['index' => $index, 'reason' => 'missing_project_context', 'query' => mb_substr($query, 0, 200)];
                continue;
            }

            $row = SearchResultService::make()->save($query, $payload, $projectId, $notes);
            $saved[] = [
                'id' => (string) $row->id,
                'query' => $query,
                'chunks_count' => count($payload['chunk_ids']),
                'project_id' => $projectId,
                'notes' => $notes,
            ];
        }

        $this->logger()->info('[SaveSearchesResultsTool] Save completed', [
            'requested_count' => count($searchResults),
            'saved_count' => count($saved),
            'skipped_count' => count($skipped),
            'saved_ids' => array_column($saved, 'id'),
            'saved_project_ids' => array_values(array_unique(array_filter(array_column($saved, 'project_id')))),
            'saved_with_notes_count' => count(array_filter(array_column($saved, 'notes'), static fn (mixed $n): bool => is_string($n) && $n !== '')),
            'skipped' => $skipped,
        ]);

        return [
            'status' => count($saved) > 0 ? 'saved' : 'nothing_saved',
            'saved_count' => count($saved),
            'skipped_count' => count($skipped),
            'saved_ids' => array_column($saved, 'id'),
        ];
    }

    private function normalizeQuery(array $item): string
    {
        $query = $item['query'] ?? $item['description'] ?? '';

        return is_string($query) ? trim($query) : '';
    }

    private function normalizeChunkIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $ids[] = trim($item);
                continue;
            }

            if (is_string($key)) {
                $ids[] = trim($key);
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
    }

    private function normalizeNotes(array $item): ?string
    {
        $notes = $item['notes'] ?? null;
        if (! is_string($notes)) {
            return null;
        }

        $trimmed = trim($notes);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function buildResultPayload(array $chunkIds, mixed $relevantImages): array
    {
        $chunks = Chunk::query()
            ->whereIn('id', $chunkIds)
            ->with([
                'dedupMedia',
                'outgoingRelations.to_entity',
                'incomingRelations' => function ($query) {
                    $query->where('type', RelationType::BIDIRECTIONAL->value)->with('from_entity');
                },
            ])
            ->withNeighborSnippets()
            ->get()
            ->keyBy('id');

        $orderedChunks = collect($chunkIds)
            ->map(static fn (string $id) => $chunks->get($id))
            ->filter()
            ->values();

        return [
            'chunk_ids' => $orderedChunks->pluck('id')->values()->toArray(),
            'relevant_chunks' => $orderedChunks->mapWithKeys(static fn (Chunk $chunk): array => [
                (string) $chunk->id => ChunkMapper::loadAndMap($chunk),
            ])->toArray(),
            'relevant_images' => $this->normalizeRelevantImages($relevantImages),
        ];
    }

    private function normalizeRelevantImages(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (mixed $image): ?array {
            if (! is_array($image)) {
                return null;
            }

            $url = $image['url'] ?? null;
            if (! is_string($url) || trim($url) === '') {
                return null;
            }

            $content = $image['content'] ?? '';

            return [
                'url' => trim($url),
                'content' => is_string($content) ? trim($content) : '',
            ];
        }, $value)));
    }

    /**
     * @param array<int, string> $chunkIds
     */
    private function resolveProjectId(array $chunkIds): ?string
    {
        if (is_string($this->scopeProjectId) && $this->scopeProjectId !== '') {
            return $this->scopeProjectId;
        }

        if ($chunkIds === []) {
            return null;
        }

        $projectIds = Chunk::query()
            ->whereIn('id', $chunkIds)
            ->with('document:id,project_id')
            ->get()
            ->pluck('document.project_id')
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();

        if ($projectIds->isEmpty()) {
            return null;
        }

        if ($projectIds->count() > 1) {
            $this->logger()->warning('[SaveSearchesResultsTool] Multiple project IDs detected while saving; using first', [
                'project_ids' => $projectIds->all(),
            ]);
        }

        return (string) $projectIds->first();
    }
}
