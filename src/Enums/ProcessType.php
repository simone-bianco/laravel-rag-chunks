<?php

namespace SimoneBianco\LaravelRagChunks\Enums;

enum ProcessType: string
{
    case PARSING = 'parsing';
    case EMBEDDING = 'embedding';
}
