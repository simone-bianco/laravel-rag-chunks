<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents\Factories;

use LarAgent\Tool;
use SimoneBianco\LaravelAiAgents\Contracts\AgentToolFactory;
use SimoneBianco\LaravelAiAgents\Support\AgentRunContext;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SaveResponseData;

final class SaveResponseDataFactory implements AgentToolFactory
{
    public function make(array $config, AgentRunContext $context): Tool
    {
        return new SaveResponseData();
    }

    public function editableParameters(): array
    {
        return (new SaveResponseData())->editableParameters();
    }
}
