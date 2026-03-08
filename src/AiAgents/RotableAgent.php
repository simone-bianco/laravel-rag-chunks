<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use LarAgent\Agent;
use SimoneBianco\LaravelRagChunks\AiAgents\Traits\InjectsRotatedOpenAIKey;

class RotableAgent extends Agent
{
    use InjectsRotatedOpenAIKey;

    public function __construct($key, bool $usesUserId = false, ?string $group = null)
    {
        $this->injectRotatedOpenAIKey();

        parent::__construct($key, $usesUserId, $group);
    }
}
