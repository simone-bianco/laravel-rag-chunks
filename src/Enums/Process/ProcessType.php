<?php

namespace SimoneBianco\LaravelRagChunks\Enums\Process;

enum ProcessType: string
{
    case PARSING = 'parsing';
    case EMBEDDING = 'embedding';
    case TAGS_GENERATION = 'tags_generation';
}
