<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use SimoneBianco\LaravelDedupMedia\Traits\HasDedupMedia;
use SimoneBianco\LaravelRagChunks\Builders\ChunkBuilder;
use SimoneBianco\LaravelRagChunks\Models\Traits\InteractsWithRagRelations;
use SimoneBianco\LaravelRagChunks\Traits\HasNearestNeighbors;
use SimoneBianco\LaravelSimpleTags\HasTags;
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;

/**
 * @method static ChunkBuilder query()
 * @method ChunkBuilder whereDocumentId(?string $documentId)
 * @method ChunkBuilder whereContentLength(?int $min, ?int $max)
 * @method ChunkBuilder whereDirty(?bool $dirty)
 * @method ChunkBuilder whereHasEmbedding(?bool $has)
 * @method ChunkBuilder whereChunkTags(?array $tagGroups)
 * @method ChunkBuilder whereKeywordSearch(?string $text, bool $caseSensitive = false)
 * @method ChunkBuilder whereBasicFilters(?array $chunksIds, ?string $textSearch, ?array $keywordsSearch)
 * @method ChunkBuilder whereAliases(?array $docAliases, ?array $projAliases)
 * @method ChunkBuilder whereTagFilters(?Collection $tagFilters)
 * @method ChunkBuilder withHybridRanking(?array $contentVector, ?array $questionsVector, ?array $tagsVector, ?float $weightContent, ?float $weightQuestions, ?float $weightTags)
 *
 * @mixin ChunkBuilder
 */
class Chunk extends Model
{
    use HasDedupMedia, HasNearestNeighbors, HasTags, HasUuids, InteractsWithRagRelations;

    protected $guarded = [];

    protected $fillable = [
        'document_id',
        'content',
        'hash',
        'embedding',
        'tags',
        'tags_embedding',
        'page',
        'order',
        'is_image',
        'questions',
        'questions_embedding',
        'is_content_dirty',
        'is_questions_dirty',
        'is_tags_dirty',
    ];

    protected function casts()
    {
        return [
            'embedding' => VectorArray::class,
            'tags_embedding' => VectorArray::class,
            'questions_embedding' => VectorArray::class,
            'tags' => 'array',
            'questions' => 'array',
            'is_image' => 'boolean',
            'is_content_dirty' => 'boolean',
            'is_questions_dirty' => 'boolean',
            'is_tags_dirty' => 'boolean',
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

    /**
     * Store chunk media on the public disk so images are accessible from the frontend.
     */
    public function getMediaDisk(): ?string
    {
        return 'public';
    }

    public function scopeWithNeighborSnippets(Builder $query, int $chars = 200): Builder
    {
        $prevBase = self::from('chunks as neighbors')
            ->whereColumn('neighbors.document_id', 'chunks.document_id')
            ->whereRaw('neighbors.order = chunks.order - 1')
            ->limit(1);

        $nextBase = self::from('chunks as neighbors')
            ->whereColumn('neighbors.document_id', 'chunks.document_id')
            ->whereRaw('neighbors.order = chunks.order + 1')
            ->limit(1);

        return $query->addSelect([
            'prev_snippet' => (clone $prevBase)->selectRaw("RIGHT(neighbors.content, $chars)"),
            'prev_snippet_id' => (clone $prevBase)->select('neighbors.id'),

            'next_snippet' => (clone $nextBase)->selectRaw("LEFT(neighbors.content, $chars)"),
            'next_snippet_id' => (clone $nextBase)->select('neighbors.id'),
        ]);
    }
}
