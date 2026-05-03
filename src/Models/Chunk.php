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
 * @method ChunkBuilder whereHasImage(?bool $has)
 * @method ChunkBuilder whereHasRelations(?bool $has)
 * @method ChunkBuilder whereHasChapter(?bool $has)
 * @method ChunkBuilder whereChapters(?array $chapters)
 * @method ChunkBuilder whereChunkTags(?array $tagGroups)
 * @method ChunkBuilder whereKeywordSearch(?string $text, bool $caseSensitive = false)
 * @method ChunkBuilder whereDocumentSearch(?string $text)
 * @method ChunkBuilder whereBasicFilters(?array $chunksIds, ?string $textSearch, ?array $keywordsSearch, string $keywordsSearchMode = 'AND', ?array $chapters = null)
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
        'chapter',
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

    protected $attributes = [
        'is_image' => false,
    ];

    protected function casts()
    {
        return [
            'is_image' => 'boolean',
            'embedding' => VectorArray::class,
            'tags_embedding' => VectorArray::class,
            'questions_embedding' => VectorArray::class,
            'tags' => 'array',
            'questions' => 'array',
            'is_content_dirty' => 'boolean',
            'is_questions_dirty' => 'boolean',
            'is_tags_dirty' => 'boolean',
        ];
    }

    protected $appends = [
        'is_dirty',
    ];

    /**
     * Compute is_dirty correctly even when embedding columns have been unset
     * (e.g. by transformForFrontend) or were never loaded.
     *
     * If embedding attributes are present in $this->attributes, check for null.
     * If they were unset/never loaded, skip those checks (don't assume dirty).
     */
    public function getIsDirtyAttribute(): bool
    {
        // If already computed via SQL (withIsDirty scope), use that value directly
        if (array_key_exists('is_dirty', $this->attributes)) {
            return (bool) $this->attributes['is_dirty'];
        }

        $dirty = $this->is_content_dirty
            || $this->is_tags_dirty
            || $this->is_questions_dirty;

        // Only check embedding nulls if the attributes are actually present
        // (i.e. they were loaded from DB and not yet unset by transformForFrontend)
        if (! $dirty && array_key_exists('embedding', $this->attributes)) {
            $dirty = $this->attributes['embedding'] === null;
        }
        if (! $dirty && array_key_exists('tags_embedding', $this->attributes)) {
            $dirty = $this->attributes['tags_embedding'] === null;
        }
        if (! $dirty && array_key_exists('questions_embedding', $this->attributes)) {
            $dirty = $this->attributes['questions_embedding'] === null;
        }

        return $dirty;
    }

    public function newEloquentBuilder($query): ChunkBuilder
    {
        return new ChunkBuilder($query);
    }

    /**
     * Override HasTags::setTagsAttribute so that assigning an array of strings
     * writes to the `tags` JSON column instead of syncing the taggable relation.
     * Classic/deterministic tags must be managed via syncTagIds() / attachTags().
     */
    public function setTagsAttribute($tags): void
    {
        $this->attributes['tags'] = is_array($tags)
            ? json_encode(array_values($tags), JSON_UNESCAPED_UNICODE)
            : $tags;
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

    /**
     * Add a SQL-computed `is_dirty` column so the accessor can use it directly
     * without relying on embedding attribute presence in PHP.
     *
     * Ensures SELECT * is present first — addSelect() on a fresh query
     * would otherwise replace the implicit * with only the raw expression.
     */
    public function scopeWithIsDirty(Builder $query): Builder
    {
        if (empty($query->getQuery()->columns)) {
            $query->select('*');
        }

        return $query->addSelect(\Illuminate\Support\Facades\DB::raw(
            '(COALESCE(is_content_dirty, false) OR COALESCE(is_tags_dirty, false) OR COALESCE(is_questions_dirty, false) OR embedding IS NULL OR tags_embedding IS NULL OR questions_embedding IS NULL) AS is_dirty'
        ));
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
