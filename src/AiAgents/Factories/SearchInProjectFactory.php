<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents\Factories;

use LarAgent\Tool;
use SimoneBianco\LaravelAiAgents\Contracts\AgentToolFactory;
use SimoneBianco\LaravelAiAgents\Support\AgentRunContext;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchInProject;

final class SearchInProjectFactory implements AgentToolFactory
{
    public function make(array $config, AgentRunContext $context): Tool
    {
        $projectAlias = (string) ($context->get('project_alias') ?? $config['project_alias'] ?? '');
        $documentAlias = $context->get('document_alias') ?? ($config['document_alias'] ?? null);

        return new SearchInProject($projectAlias, $documentAlias !== null ? (string) $documentAlias : null);
    }

    public function editableParameters(): array
    {
        return (new SearchInProject('_'))->editableParameters();
    }
}
