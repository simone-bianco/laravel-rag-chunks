<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

class GetPreviousChunk extends GetAdjacentChunk
{
    public function __construct()
    {
        parent::__construct('get_previous_chunk', 'Get the chunk immediately before a given chunk in the same document');
    }

    protected function direction(): int
    {
        return -1;
    }
}
