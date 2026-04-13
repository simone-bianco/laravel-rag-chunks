<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents\Factories;

use LarAgent\Tool;
use SimoneBianco\LaravelAiAgents\Contracts\AgentToolFactory;
use SimoneBianco\LaravelAiAgents\Support\AgentRunContext;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchInAllowedProjects;

final class SearchInAllowedProjectsFactory implements AgentToolFactory
{
    public function make(array $config, AgentRunContext $context): Tool
    {
        $aliases = $context->get('allowed_project_aliases')
            ?? ($config['allowed_project_aliases'] ?? []);

        if (! is_array($aliases)) {
            $aliases = [];
        }

        return new SearchInAllowedProjects($aliases);
    }

    public function editableParameters(): array
    {
        return (new SearchInAllowedProjects([]))->editableParameters();
    }
}
