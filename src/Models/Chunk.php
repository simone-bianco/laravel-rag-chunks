<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SimoneBianco\LaravelRagChunks\Enums\ChunkModel;
use SimoneBianco\LaravelRagChunks\Traits\HasNearestNeighbors;
use Spatie\Tags\HasTags;
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;
use Illuminate\Database\Eloquent\Builder;

class Chunk extends Model
{
    use HasUuids, HasTags, HasNearestNeighbors;

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

    public function scopeWithNeighborContext(Builder $query, int $page, int $chars = 100): Builder
    {
        return $query->whereIn('page', [$page - 1, $page, $page + 1])
            ->select('id', 'document_id', 'page')
            ->selectRaw("
                CASE
                    WHEN page = ? THEN content
                    WHEN page < ? THEN RIGHT(content, ?)
                    WHEN page > ? THEN LEFT(content, ?)
                END as smart_content
            ", [$page, $page, $chars, $page, $chars]);
    }
}
