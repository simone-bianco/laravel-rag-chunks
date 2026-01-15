<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Model;
use SimoneBianco\LaravelRagChunks\Casts\ProcessLogCast;

class Process extends Model
{
    protected $fillable = [
        'processable',
        'status',
        'type',
        'error',
        'log',
    ];

    protected $casts = [
        'log' => ProcessLogCast::class,
    ];
}
