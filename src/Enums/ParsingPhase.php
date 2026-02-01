<?php

namespace SimoneBianco\LaravelRagChunks\Enums;

use SimoneBianco\LaravelRagChunks\Enums\Traits\HasValues;

enum ParsingPhase: string
{
    use HasValues;

    case DISPATCHING = 'dispatching';
    case DISPATCHED = 'dispatched';
    case POLLING = 'polling';
    case SAVED = 'saved';
    case REFINING = 'refining';
    case REFINED = 'refined';
    case POST_PROCESSING = 'post_processing';
    case POST_PROCESSED = 'post_processed';
    case COMPLETED = 'completed';
}
