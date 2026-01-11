<?php

namespace SimoneBianco\LaravelRagChunks\Enums;

use SimoneBianco\LaravelRagChunks\Enums\Traits\HasValues;

enum TagFilterMode: string
{
    use HasValues;

    case ANY = 'ANY';
    case ALL = 'ALL';
}
