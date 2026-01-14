<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SimoneBianco\LaravelRagChunks\Builders\ChunkBuilder;
use SimoneBianco\LaravelRagChunks\Traits\HasNearestNeighbors;
use SimoneBianco\LaravelSimpleTags\HasTags;
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;

class Chunk extends Model
{
    use HasNearestNeighbors, HasTags, HasUuids;

    protected $guarded = [];

    protected $fillable = [
        'document_id',
        'content',
        'semantic_tags',
        'semantic_tags_embedding',
        'keywords',
        'hash',
        'embedding',
        'page',
    ];

    protected function casts()
    {
        $embeddingCast = config('rag_chunks.embedding_cast', VectorArray::class);
        return [
            'embedding' => $embeddingCast,
            'semantic_tags_embedding' => $embeddingCast,
            'keywords' => 'array',
        ];
    }

    public function newEloquentBuilder($query): ChunkBuilder
    {
        return new ChunkBuilder($query);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function scopeWithNeighborSnippets(Builder $query, int $chars = 200): Builder
    {
        $prevBase = self::from('chunks as neighbors')
            ->whereColumn('neighbors.document_id', 'chunks.document_id')
            ->whereRaw('neighbors.page = chunks.page - 1')
            ->limit(1);

        $nextBase = self::from('chunks as neighbors')
            ->whereColumn('neighbors.document_id', 'chunks.document_id')
            ->whereRaw('neighbors.page = chunks.page + 1')
            ->limit(1);

        return $query->addSelect([
            'prev_snippet' => (clone $prevBase)->selectRaw("RIGHT(neighbors.content, $chars)"),
            'prev_snippet_id' => (clone $prevBase)->select('neighbors.id'),

            'next_snippet' => (clone $nextBase)->selectRaw("LEFT(neighbors.content, $chars)"),
            'next_snippet_id' => (clone $nextBase)->select('neighbors.id'),
        ]);
    }
}
