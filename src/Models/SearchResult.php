<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SimoneBianco\LaravelAiAgents\Models\AiAgent;
use SimoneBianco\LaravelRagChunks\Models\Project;
use SimoneBianco\LaravelRagChunks\Traits\HasNearestNeighbors;
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;

class SearchResult extends Model
{
    use HasUuids, HasNearestNeighbors;

    protected $fillable = [
        'ai_agent_id',
        'project_id',
        'query',
        'notes',
        'summary',
        'is_complete',
        'hash',
        'results',
        'embedding',
        'hits',
    ];

    protected function casts()
    {
        return [
            'embedding'   => VectorArray::class,
            'notes'       => 'string',
            'summary'     => 'string',
            'is_complete' => 'boolean',
            'hash'        => 'string',
            'results'     => 'array',
            'hits'        => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            $results = is_array($model->results) ? $model->results : [];
            $chunkIds = self::extractSortedChunkIdsForHash($results);

            $model->hash = $chunkIds !== []
                ? hash('sha256', implode(',', $chunkIds))
                : null;
        });
    }

    /**
     * @param array<string, mixed> $results
     * @return array<int, string>
     */
    private static function extractSortedChunkIdsForHash(array $results): array
    {
        // New format: documents.{alias}.chunk_ids
        $documents = is_array($results['documents'] ?? null) ? $results['documents'] : [];
        if ($documents !== []) {
            $ids = [];
            foreach ($documents as $docStats) {
                if (! is_array($docStats)) {
                    continue;
                }
                $chunkIds = is_array($docStats['chunk_ids'] ?? null) ? $docStats['chunk_ids'] : [];
                foreach ($chunkIds as $chunkId) {
                    if (is_string($chunkId) && $chunkId !== '') {
                        $ids[] = $chunkId;
                    }
                }
            }
            sort($ids);

            return $ids;
        }

        // Legacy format
        $ids = is_array($results['chunk_ids'] ?? null) ? $results['chunk_ids'] : [];
        $ids = array_values(array_unique(array_filter(
            $ids,
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        )));
        sort($ids);

        return $ids;
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
