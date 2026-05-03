<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use SimoneBianco\LaravelRagChunks\Models\Project;

class SearchResultAutomationSettings
{
    public function __construct(
        private readonly ?float $autoMergeDistance,
        private readonly ?int $optimizationHitThreshold,
        private readonly ?bool $autoMergeEnabled,
        private readonly ?bool $autoCompactEnabled,
        private readonly ?bool $autoSplitEnabled,
    ) {}

    public static function forProjectId(?string $projectId): self
    {
        $normalizedProjectId = is_string($projectId) ? trim($projectId) : '';
        if ($normalizedProjectId === '') {
            return self::defaults();
        }

        $project = Project::query()->find($normalizedProjectId);
        if (! $project instanceof Project) {
            return self::defaults();
        }

        $settings = $project->settings;

        return new self(
            autoMergeDistance: $settings->search_result_auto_merge_distance,
            optimizationHitThreshold: $settings->search_result_optimization_hit_threshold,
            autoMergeEnabled: $settings->search_result_auto_merge_enabled,
            autoCompactEnabled: $settings->search_result_auto_compact_enabled,
            autoSplitEnabled: $settings->search_result_auto_split_enabled,
        );
    }

    public static function defaults(): self
    {
        return new self(null, null, null, null, null);
    }

    public function autoMergeDistance(float $fallback): float
    {
        $value = $this->autoMergeDistance ?? $fallback;

        return max(0.0, min(1.0, $value));
    }

    public function optimizationHitThreshold(int $fallback): int
    {
        $value = $this->optimizationHitThreshold ?? $fallback;

        return max(0, $value);
    }

    /** @return array<int, string> */
    public function automaticOperations(): array
    {
        $operations = [];

        if ($this->autoMergeEnabled ?? true) {
            $operations[] = 'merge';
        }

        if ($this->autoCompactEnabled ?? true) {
            $operations[] = 'compact';
        }

        if ($this->autoSplitEnabled ?? true) {
            $operations[] = 'split';
        }

        return $operations;
    }

    public function allows(string $operation): bool
    {
        return in_array($operation, $this->automaticOperations(), true);
    }

    /** @return array<int, string> */
    public function optimizationOperations(): array
    {
        return array_values(array_intersect($this->automaticOperations(), ['compact', 'split']));
    }
}
