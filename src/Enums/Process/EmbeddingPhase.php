<?php

namespace SimoneBianco\LaravelRagChunks\Enums\Process;

use SimoneBianco\LaravelRagChunks\Enums\Traits\HasValues;

enum EmbeddingPhase: string
{
    use HasValues;

    case STARTED = 'started';
    case COMPLETED = 'completed';
}
