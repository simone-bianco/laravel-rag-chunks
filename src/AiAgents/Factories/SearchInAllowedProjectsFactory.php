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

        $includeImages = (bool) ($context->get('include_images') ?? $config['include_images'] ?? true);

        $callingAgentId = $context->get('calling_agent_id') ?? $config['calling_agent_id'] ?? null;
        $callingAgentId = is_string($callingAgentId) && $callingAgentId !== '' ? $callingAgentId : null;

        $historyEnabled = (bool) (($config['history_enabled'] ?? $context->get('history_enabled')) ?? false);

        return new SearchInAllowedProjects(
            allowedProjectAliases: $aliases,
            includeImages: $includeImages,
            callingAgentId: $callingAgentId,
            historyEnabled: $historyEnabled,
        );
    }

    public function editableParameters(): array
    {
        return (new SearchInAllowedProjects([]))->editableParameters();
    }
}
