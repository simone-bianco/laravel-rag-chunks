<?php

namespace SimoneBianco\LaravelRagChunks\Enums;

enum SearchDepth: string
{
    case Shallow  = 'shallow';
    case Standard = 'standard';
    case Deep     = 'deep';
}
