<?php

namespace SimoneBianco\LaravelRagChunks\Enums;

use SimoneBianco\LaravelRagChunks\Enums\Traits\HasValues;

enum DocumentExtension: string
{
    use HasValues;

    case PDF = 'pdf';
    case MARKDOWN = 'md';
    case TXT = 'txt';
}
