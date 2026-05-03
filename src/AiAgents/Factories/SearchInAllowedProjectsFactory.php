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

        $compactionThreshold = (float) ($context->get('compaction_threshold')
            ?? $config['compaction_threshold']
            ?? config('rag_chunks.search_results.auto_merge_distance', 0.11));

        $optimizationChunkThreshold = (int) ($context->get('optimization_chunk_threshold')
            ?? $config['optimization_chunk_threshold']
            ?? config('rag_chunks.search_results.optimization_chunk_threshold', 12));

        $optimizationHitThreshold = (int) ($context->get('optimization_hit_threshold')
            ?? $config['optimization_hit_threshold']
            ?? config('rag_chunks.search_results.optimization_hit_threshold', 5));

        $callingAgentIdRaw = $context->get('calling_agent_id') ?? $config['calling_agent_id'] ?? null;
        $callingAgentId = is_string($callingAgentIdRaw) && trim($callingAgentIdRaw) !== ''
            ? trim($callingAgentIdRaw)
            : null;

        return new SearchInAllowedProjects(
            allowedProjectAliases: $aliases,
            includeImages: $includeImages,
            historyEnabled: $historyEnabled,
            compactionThreshold: $compactionThreshold,
            optimizationChunkThreshold: $optimizationChunkThreshold,
            optimizationHitThreshold: $optimizationHitThreshold,
            callingAgentId: $callingAgentId,
        );
    }

    public function editableParameters(): array
    {
        return (new SearchInAllowedProjects([]))->editableParameters();
    }
}
