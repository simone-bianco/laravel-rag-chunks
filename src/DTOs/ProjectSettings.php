<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

class ProjectSettings
{
    public function __construct(
        public ?string $post_processor_agent_instructions = null,
        public ?string $search_agent_instructions = null,
        public ?float $search_result_auto_merge_distance = null,
        public ?int $search_result_optimization_chunk_threshold = null,
        public ?int $search_result_optimization_hit_threshold = null,
        public ?int $search_result_max_summary_words = null,
        public ?bool $search_result_auto_merge_enabled = null,
        public ?bool $search_result_auto_compact_enabled = null,
        public ?bool $search_result_auto_split_enabled = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            post_processor_agent_instructions: $data['post_processor_agent_instructions'] ?? null,
            search_agent_instructions: $data['search_agent_instructions'] ?? null,
            search_result_auto_merge_distance: self::nullableFloat($data['search_result_auto_merge_distance'] ?? null),
            search_result_optimization_chunk_threshold: self::nullableInt($data['search_result_optimization_chunk_threshold'] ?? null),
            search_result_optimization_hit_threshold: self::nullableInt($data['search_result_optimization_hit_threshold'] ?? null),
            search_result_max_summary_words: self::nullableInt($data['search_result_max_summary_words'] ?? null),
            search_result_auto_merge_enabled: self::nullableBool($data['search_result_auto_merge_enabled'] ?? null),
            search_result_auto_compact_enabled: self::nullableBool($data['search_result_auto_compact_enabled'] ?? null),
            search_result_auto_split_enabled: self::nullableBool($data['search_result_auto_split_enabled'] ?? null),
        );
    }

    public function toArray(): array
    {
        return [
            'post_processor_agent_instructions' => $this->post_processor_agent_instructions,
            'search_agent_instructions' => $this->search_agent_instructions,
            'search_result_auto_merge_distance' => $this->search_result_auto_merge_distance,
            'search_result_optimization_chunk_threshold' => $this->search_result_optimization_chunk_threshold,
            'search_result_optimization_hit_threshold' => $this->search_result_optimization_hit_threshold,
            'search_result_max_summary_words' => $this->search_result_max_summary_words,
            'search_result_auto_merge_enabled' => $this->search_result_auto_merge_enabled,
            'search_result_auto_compact_enabled' => $this->search_result_auto_compact_enabled,
            'search_result_auto_split_enabled' => $this->search_result_auto_split_enabled,
        ];
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private static function nullableBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return (bool) $value;
    }
}
