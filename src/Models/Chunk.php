<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SimoneBianco\LaravelRagChunks\Enums\ChunkModel;
use SimoneBianco\LaravelRagChunks\Traits\HasNearestNeighbors;
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;
use Illuminate\Database\Eloquent\Builder;

class Chunk extends Model
{
    use HasUuids, HasNearestNeighbors;

    protected ChunkModel $driver;
    protected $guarded = [];

    protected $fillable = [
        'content',
        'hash',
        'embedding',
        'page'
    ];

    public function __construct(array $attributes = [])
    {
        $this->driver = config('rag_chunks.driver', ChunkModel::POSTGRES);
        parent::__construct($attributes);
    }

    protected function casts()
    {
        return [
            'embedding' => $this->driver === ChunkModel::POSTGRES ? VectorArray::class : 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function scopeWithNeighborSnippets(Builder $query, int $chars = 200): Builder
    {
        $prevQuery = self::from('chunks as neighbors')
            ->selectRaw("RIGHT(neighbors.content, $chars)")
            ->whereColumn('neighbors.document_id', 'chunks.document_id')
            ->whereRaw('neighbors.page = chunks.page - 1')
            ->limit(1);

        $nextQuery = self::from('chunks as neighbors')
            ->selectRaw("LEFT(neighbors.content, $chars)")
            ->whereColumn('neighbors.document_id', 'chunks.document_id')
            ->whereRaw('neighbors.page = chunks.page + 1')
            ->limit(1);

        return $query->addSelect([
            'prev_snippet' => $prevQuery,
            'next_snippet' => $nextQuery
        ]);
    }
}
