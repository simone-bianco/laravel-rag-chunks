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

        $historyEnabled = (bool) (($config['history_enabled'] ?? $context->get('history_enabled')) ?? false);

        $compactionThreshold = (float) ($context->get('compaction_threshold') ?? $config['compaction_threshold'] ?? 0.1);

        $callingAgentIdRaw = $context->get('calling_agent_id') ?? $config['calling_agent_id'] ?? null;
        $callingAgentId = is_string($callingAgentIdRaw) && trim($callingAgentIdRaw) !== ''
            ? trim($callingAgentIdRaw)
            : null;

        return new SearchInAllowedProjects(
            allowedProjectAliases: $aliases,
            includeImages: $includeImages,
            historyEnabled: $historyEnabled,
            compactionThreshold: $compactionThreshold,
            callingAgentId: $callingAgentId,
        );
    }

    public function editableParameters(): array
    {
        return (new SearchInAllowedProjects([]))->editableParameters();
    }
}
