<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SimoneBianco\LaravelRagChunks\Enums\ChunkModel;
use Spatie\Tags\HasTags;
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;

class Chunk extends Model
{
    use HasUuids, HasTags;

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

    protected static function boot()
    {
        parent::boot();
        
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
}
