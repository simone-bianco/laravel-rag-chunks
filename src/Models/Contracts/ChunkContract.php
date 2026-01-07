<?php

namespace SimoneBianco\LaravelRagChunks\Models\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface ChunkContract
{
    /**
     * Scope a query to find the nearest neighbors to a given vector.
     *
     * @param  $query
     * @param  array  $vector
     */
    public function scopeNearest($query, array $vector);
}
