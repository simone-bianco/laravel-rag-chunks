<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents\Factories;

use Illuminate\Support\Facades\Log;
use LarAgent\Tool;
use SimoneBianco\LaravelAiAgents\Contracts\AgentToolFactory;
use SimoneBianco\LaravelAiAgents\Support\AgentRunContext;
use SimoneBianco\LaravelRagChunks\AiAgents\SearchScope;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchTool;
use SimoneBianco\LaravelRagChunks\Enums\SearchDepth;
use SimoneBianco\LaravelRagChunks\Enums\SearchScopeType;

final class SearchInProjectFactory implements AgentToolFactory
{
    public function make(array $config, AgentRunContext $context): Tool
    {
        $scopes = $this->resolveScopes($config, $context);

        $includeImages = (bool) ($context->get('include_images') ?? $config['include_images'] ?? true);

        $model = $context->get('model') ?? $config['model'] ?? null;

        $deepRaw = $context->get('deep') ?? $config['deep'] ?? SearchDepth::Standard->value;
        $deep    = SearchDepth::tryFrom((string) $deepRaw) ?? SearchDepth::Standard;

        $maxParallel = (int) ($context->get('max_parallel_agents') ?? $config['max_parallel_agents'] ?? 8);

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

        Log::channel('search')->debug('[SearchInProjectFactory] tool config resolved', [
            'scopes_count' => count($scopes),
            'scopes' => array_map(
                static fn (SearchScope $scope) => $scope->type->value . ':' . $scope->alias,
                $scopes,
            ),
            'include_images' => $includeImages,
            'model' => $model,
            'deep' => $deep->value,
            'max_parallel' => $maxParallel,
            'history_enabled' => $historyEnabled,
            'compaction_threshold' => $compactionThreshold,
            'optimization_chunk_threshold' => $optimizationChunkThreshold,
            'optimization_hit_threshold' => $optimizationHitThreshold,
            'calling_agent_id' => $callingAgentId,
        ]);

        return new SearchTool(
            scopes: $scopes,
            includeImages: $includeImages,
            model: $model !== null ? (string) $model : null,
            deep: $deep,
            maxParallel: $maxParallel,
            historyEnabled: $historyEnabled,
            compactionThreshold: $compactionThreshold,
            optimizationChunkThreshold: $optimizationChunkThreshold,
            optimizationHitThreshold: $optimizationHitThreshold,
            callingAgentId: $callingAgentId,
        );
    }

    public function editableParameters(): array
    {
        return [];
    }

    /**
     * Resolves scopes from config/context.
     *
     * Accepts:
     *   - scopes: [{alias: '...', type: 'project|document'}, ...]
     *   - project_aliases / project_alias (backwards compat → project scopes)
     *   - document_aliases / document_alias (backwards compat → document scopes)
     *
     * @return SearchScope[]
     */
    private function resolveScopes(array $config, AgentRunContext $context): array
    {
        $rawScopes = $context->get('scopes') ?? $config['scopes'] ?? null;

        if (is_array($rawScopes) && !empty($rawScopes)) {
            return array_values(array_filter(array_map(
                function (mixed $entry): ?SearchScope {
                    if (! is_array($entry)) {
                        return null;
                    }

                    $alias = trim((string) ($entry['alias'] ?? ''));
                    $type  = SearchScopeType::tryFrom((string) ($entry['type'] ?? ''));

                    return ($alias !== '' && $type !== null)
                        ? new SearchScope($alias, $type)
                        : null;
                },
                $rawScopes,
            )));
        }

        $scopes = [];

        foreach ($this->resolveAliasArray(
            $context->get('project_aliases') ?? $config['project_aliases'] ?? null,
            $context->get('project_alias')   ?? $config['project_alias']   ?? null,
        ) as $alias) {
            $scopes[] = new SearchScope($alias, SearchScopeType::Project);
        }

        // Backward/interop fallback: AgentToolBuilder enriches context with
        // allowed_project_aliases from scope bindings. If explicit project_aliases
        // are not provided, reuse them for search_in_project scopes.
        foreach ($this->resolveAliasArray(
            $context->get('allowed_project_aliases') ?? $config['allowed_project_aliases'] ?? null,
            null,
        ) as $alias) {
            $scopes[] = new SearchScope($alias, SearchScopeType::Project);
        }

        foreach ($this->resolveAliasArray(
            $context->get('document_aliases') ?? $config['document_aliases'] ?? null,
            $context->get('document_alias')   ?? $config['document_alias']   ?? null,
        ) as $alias) {
            $scopes[] = new SearchScope($alias, SearchScopeType::Document);
        }

        // Deduplicate by type+alias to avoid repeated search passes.
        $unique = [];
        foreach ($scopes as $scope) {
            $key = $scope->type->value . ':' . $scope->alias;
            $unique[$key] = $scope;
        }

        return array_values($unique);
    }

    private function resolveAliasArray(mixed $array, mixed $single): array
    {
        if (is_array($array) && !empty($array)) {
            return array_values(array_filter(array_map(
                static fn ($v) => is_string($v) ? trim($v) : null,
                $array,
            )));
        }

        if (is_string($single) && $single !== '') {
            return [trim($single)];
        }

        return [];
    }
}
