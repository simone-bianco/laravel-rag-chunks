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

        $compactionThreshold = $context->get('compaction_threshold')
            ?? $config['compaction_threshold']
            ?? null;
        $compactionThreshold = $compactionThreshold !== null ? (float) $compactionThreshold : null;

        $optimizationChunkThreshold = $context->get('optimization_chunk_threshold')
            ?? $config['optimization_chunk_threshold']
            ?? null;
        $optimizationChunkThreshold = $optimizationChunkThreshold !== null ? (int) $optimizationChunkThreshold : null;

        $callingAgentIdRaw = $context->get('calling_agent_id') ?? $config['calling_agent_id'] ?? null;
        $callingAgentId = is_string($callingAgentIdRaw) && trim($callingAgentIdRaw) !== ''
            ? trim($callingAgentIdRaw)
            : null;

        $persistentMemoryEnabled = $context->get('persistent_memory_enabled')
            ?? $config['persistent_memory_enabled']
            ?? null;
        $persistentMemoryEnabled = $persistentMemoryEnabled !== null ? (bool) $persistentMemoryEnabled : null;

        $persistentMemoryMaxWords = $context->get('persistent_memory_max_words')
            ?? $config['persistent_memory_max_words']
            ?? null;
        $persistentMemoryMaxWords = $persistentMemoryMaxWords !== null ? (int) $persistentMemoryMaxWords : null;

        return new SearchInAllowedProjects(
            allowedProjectAliases: $aliases,
            includeImages: $includeImages,
            historyEnabled: $historyEnabled,
            compactionThreshold: $compactionThreshold,
            optimizationChunkThreshold: $optimizationChunkThreshold,
            callingAgentId: $callingAgentId,
            persistentMemoryEnabled: $persistentMemoryEnabled,
            persistentMemoryMaxWords: $persistentMemoryMaxWords !== 0 ? $persistentMemoryMaxWords : null,
        );
    }

    public function editableParameters(): array
    {
        return (new SearchInAllowedProjects([]))->editableParameters();
    }
}
