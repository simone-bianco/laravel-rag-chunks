<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Closure;
use SimoneBianco\LaravelRagChunks\Models\Project;

class SearchResultAutomationSettings
{
    /** @var null|Closure(): array{auto_merge_distance: ?float, optimization_chunk_threshold: ?int, optimization_hit_threshold: ?int, max_summary_words: ?int} */
    protected static ?Closure $globalDefaultsResolver = null;

    public function __construct(
        private readonly ?float $autoMergeDistance,
        private readonly ?int $optimizationChunkThreshold,
        private readonly ?int $optimizationHitThreshold,
        private readonly ?int $maxSummaryWords,
        private readonly ?bool $autoMergeEnabled,
        private readonly ?bool $autoCompactEnabled,
        private readonly ?bool $autoSplitEnabled,
    ) {}

    /**
     * Register a resolver for global defaults (called when project value is null).
     * The closure should return an array with optional float/int values.
     *
     * @param  Closure(): array{auto_merge_distance?: ?float, optimization_chunk_threshold?: ?int, optimization_hit_threshold?: ?int, max_summary_words?: ?int}  $resolver
     */
    public static function resolveGlobalDefaultsUsing(Closure $resolver): void
    {
        self::$globalDefaultsResolver = $resolver;
    }

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
            optimizationChunkThreshold: $settings->search_result_optimization_chunk_threshold ?? null,
            optimizationHitThreshold: $settings->search_result_optimization_hit_threshold,
            maxSummaryWords: $settings->search_result_max_summary_words,
            autoMergeEnabled: $settings->search_result_auto_merge_enabled,
            autoCompactEnabled: $settings->search_result_auto_compact_enabled,
            autoSplitEnabled: $settings->search_result_auto_split_enabled,
        );
    }

    public static function defaults(): self
    {
        $global = self::resolveGlobalDefaults();

        return new self(
            autoMergeDistance: $global['auto_merge_distance'] ?? null,
            optimizationChunkThreshold: $global['optimization_chunk_threshold'] ?? null,
            optimizationHitThreshold: $global['optimization_hit_threshold'] ?? null,
            maxSummaryWords: $global['max_summary_words'] ?? null,
            autoMergeEnabled: null,
            autoCompactEnabled: null,
            autoSplitEnabled: null,
        );
    }

    /**
     * @return array{auto_merge_distance: ?float, optimization_chunk_threshold: ?int, optimization_hit_threshold: ?int, max_summary_words: ?int}
     */
    private static function resolveGlobalDefaults(): array
    {
        if (self::$globalDefaultsResolver === null) {
            return [
                'auto_merge_distance' => null,
                'optimization_chunk_threshold' => null,
                'optimization_hit_threshold' => null,
                'max_summary_words' => null,
            ];
        }

        $resolved = (self::$globalDefaultsResolver)();

        return [
            'auto_merge_distance' => is_array($resolved) && array_key_exists('auto_merge_distance', $resolved)
                ? (is_numeric($resolved['auto_merge_distance']) ? (float) $resolved['auto_merge_distance'] : null)
                : null,
            'optimization_chunk_threshold' => is_array($resolved) && array_key_exists('optimization_chunk_threshold', $resolved)
                ? (is_int($resolved['optimization_chunk_threshold']) || $resolved['optimization_chunk_threshold'] === null ? $resolved['optimization_chunk_threshold'] : (int) $resolved['optimization_chunk_threshold'])
                : null,
            'optimization_hit_threshold' => is_array($resolved) && array_key_exists('optimization_hit_threshold', $resolved)
                ? (is_int($resolved['optimization_hit_threshold']) || $resolved['optimization_hit_threshold'] === null ? $resolved['optimization_hit_threshold'] : (int) $resolved['optimization_hit_threshold'])
                : null,
            'max_summary_words' => is_array($resolved) && array_key_exists('max_summary_words', $resolved)
                ? (is_int($resolved['max_summary_words']) || $resolved['max_summary_words'] === null ? $resolved['max_summary_words'] : (int) $resolved['max_summary_words'])
                : null,
        ];
    }

    public function autoMergeDistance(float $fallback): float
    {
        $value = $this->autoMergeDistance ?? $fallback;

        return max(0.0, min(1.0, $value));
    }

    public function optimizationChunkThreshold(int $fallback): int
    {
        $value = $this->optimizationChunkThreshold ?? $fallback;

        return max(1, $value);
    }

    public function optimizationHitThreshold(int $fallback): int
    {
        $value = $this->optimizationHitThreshold ?? $fallback;

        return max(0, $value);
    }

    public function maxSummaryWords(int $fallback): int
    {
        $value = $this->maxSummaryWords ?? $fallback;

        return max(100, min(5000, $value));
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
