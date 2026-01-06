<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'alias',
        'hash',
        'path',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array'
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }
}
