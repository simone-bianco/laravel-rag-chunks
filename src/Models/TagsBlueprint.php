<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TagsBlueprint extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'tags_by_type' => 'array',
    ];
}
