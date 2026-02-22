<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Model;
use SimoneBianco\LaravelRagChunks\Enums\RelationType;

class Relation extends Model
{
    protected $fillable = [
        'name',
        'description',
        'from_entity_id',
        'to_entity_id',
        'from_entity_type',
        'to_entity_type',
        'type'
    ];

    protected $casts = [
        'type' => RelationType::class,
    ];
}
