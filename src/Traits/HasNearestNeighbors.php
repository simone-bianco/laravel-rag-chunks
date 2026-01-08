<?php

namespace SimoneBianco\LaravelRagChunks\Traits;

use Illuminate\Database\Eloquent\Builder;

trait HasNearestNeighbors
{
    public function scopeNearestNeighbors(Builder $query, string $column, array $vector, string $distanceMetric = 'cosine'): Builder
    {
        $operator = match ($distanceMetric) {
            'euclidean' => '<->',
            'inner_product' => '<#>',
            default => '<=>',
        };

        $vectorString = '['.implode(',', $vector).']';

        return $query->orderByRaw("$column $operator ?", [$vectorString]);
    }
}
