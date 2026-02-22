<?php

namespace SimoneBianco\LaravelRagChunks\Enums;

enum RelationType: string
{
    case BIDIRECTIONAL = 'bidirectional';
    case UNIDIRECTIONAL = 'unidirectional';
}
