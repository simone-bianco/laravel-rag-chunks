<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Exception;
use LarAgent\Agent;
use LarAgent\Context\Drivers\CacheStorage;

class ImageDescriptorAgent extends Agent
{
    protected $history = CacheStorage::class;

    protected $model = 'gpt-5-mini';

    /**
     * @throws Exception
     */
    public function __construct(protected string $additionalContext, $key)
    {
        parent::__construct($key);
    }

    public function instructions(): string
    {
        return <<<INSTRUCTIONS
**Role:**
INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }
}
