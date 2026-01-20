<?php

namespace SimoneBianco\LaravelRagChunks\Enums;

enum ParserStatus: string
{
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
