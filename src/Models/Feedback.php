<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    protected $fillable = [
        'status',
        'user_text',
        'proposed_changes',
        'metadata'
    ];
}
