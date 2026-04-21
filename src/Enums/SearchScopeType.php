<?php

namespace SimoneBianco\LaravelRagChunks\Enums;

enum SearchScopeType: string
{
    case Project  = 'project';
    case Document = 'document';
}
