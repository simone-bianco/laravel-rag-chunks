<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SimoneBianco\LaravelAiAgents\Models\AiAgent;
use SimoneBianco\LaravelRagChunks\Models\Project;
use SimoneBianco\LaravelRagChunks\Traits\HasNearestNeighbors;
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;

class SearchResult extends Model
{
    use HasUuids, HasNearestNeighbors;

    protected $fillable = [
        'ai_agent_id',
        'project_id',
        'query',
        'notes',
        'results',
        'embedding',
        'hits',
    ];

    protected function casts()
    {
        return [
            'embedding' => VectorArray::class,
            'notes'     => 'string',
            'results'   => 'array',
            'hits'      => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
