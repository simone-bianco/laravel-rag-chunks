<?php

namespace SimoneBianco\LaravelRagChunks\DTOs;

class ProjectSettings
{
    public function __construct(
        public ?string $post_processor_agent_instructions = null,
        public ?string $search_agent_instructions = null,
        public ?float $search_result_auto_merge_distance = null,
        public ?int $search_result_optimization_hit_threshold = null,
        public ?bool $search_result_auto_merge_enabled = null,
        public ?bool $search_result_auto_compact_enabled = null,
        public ?bool $search_result_auto_split_enabled = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            post_processor_agent_instructions: $data['post_processor_agent_instructions'] ?? null,
            search_agent_instructions: $data['search_agent_instructions'] ?? null,
            search_result_auto_merge_distance: isset($data['search_result_auto_merge_distance'])
                ? (float) $data['search_result_auto_merge_distance']
                : null,
            search_result_optimization_hit_threshold: isset($data['search_result_optimization_hit_threshold'])
                ? (int) $data['search_result_optimization_hit_threshold']
                : null,
            search_result_auto_merge_enabled: array_key_exists('search_result_auto_merge_enabled', $data)
                ? (bool) $data['search_result_auto_merge_enabled']
                : null,
            search_result_auto_compact_enabled: array_key_exists('search_result_auto_compact_enabled', $data)
                ? (bool) $data['search_result_auto_compact_enabled']
                : null,
            search_result_auto_split_enabled: array_key_exists('search_result_auto_split_enabled', $data)
                ? (bool) $data['search_result_auto_split_enabled']
                : null,
        );
    }

    public function toArray(): array
    {
        return [
            'post_processor_agent_instructions' => $this->post_processor_agent_instructions,
            'search_agent_instructions' => $this->search_agent_instructions,
            'search_result_auto_merge_distance' => $this->search_result_auto_merge_distance,
            'search_result_optimization_hit_threshold' => $this->search_result_optimization_hit_threshold,
            'search_result_auto_merge_enabled' => $this->search_result_auto_merge_enabled,
            'search_result_auto_compact_enabled' => $this->search_result_auto_compact_enabled,
            'search_result_auto_split_enabled' => $this->search_result_auto_split_enabled,
        ];
    }
}
