<?php

namespace SimoneBianco\LaravelRagChunks\Enums\Process;

enum ProcessType: string
{
    case PARSING = 'parsing';
    case EMBEDDING = 'embedding';
}
