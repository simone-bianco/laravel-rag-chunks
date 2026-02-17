<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Tools;

class GetNextChunk extends GetAdjacentChunk
{
    public function __construct()
    {
        parent::__construct('get_next_chunk', 'Get the chunk immediately after a given chunk in the same document');
    }

    protected function direction(): int
    {
        return 1;
    }
}
