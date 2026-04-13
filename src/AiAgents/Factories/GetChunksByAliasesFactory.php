<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents\Factories;

use LarAgent\Tool;
use SimoneBianco\LaravelAiAgents\Contracts\AgentToolFactory;
use SimoneBianco\LaravelAiAgents\Support\AgentRunContext;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\GetChunksByAliases;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Project;

final class GetChunksByAliasesFactory implements AgentToolFactory
{
    public function make(array $config, AgentRunContext $context): Tool
    {
        $project = $context->get('project');

        if (! $project instanceof Project) {
            $alias = (string) ($context->get('project_alias') ?? $config['project_alias'] ?? '');
            $project = Project::query()->where('alias', $alias)->firstOrFail();
        }

        $document = $context->get('document');
        if (! $document instanceof Document) {
            $documentAlias = $context->get('document_alias') ?? ($config['document_alias'] ?? null);
            $document = $documentAlias
                ? Document::query()->where('alias', (string) $documentAlias)->first()
                : null;
        }

        return new GetChunksByAliases($project, $document);
    }

    public function editableParameters(): array
    {
        return (new GetChunksByAliases(new Project()))->editableParameters();
    }
}
