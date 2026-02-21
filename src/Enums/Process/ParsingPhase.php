<?php

namespace SimoneBianco\LaravelRagChunks\Enums\Process;

use SimoneBianco\LaravelRagChunks\Enums\Traits\HasValues;

enum ParsingPhase: string
{
    use HasValues;

    case DISPATCHING = 'dispatching';
    case DISPATCHED = 'dispatched';
    case POLLING = 'polling';
    case REFINING = 'refining';
    case REFINED = 'refined';
    case POST_PROCESSING = 'post_processing';
    case POST_PROCESSED = 'post_processed';
    case SAVING = 'saving';
    case SAVED = 'saved';
    case COMPLETED = 'completed';
}
