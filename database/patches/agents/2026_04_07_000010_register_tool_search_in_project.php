<?php

declare(strict_types=1);

use SimoneBianco\LaravelAiAgents\Models\AiAgentTool;
use SimoneBianco\LaravelRagChunks\AiAgents\Factories\SearchInProjectFactory;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchInProject;

return new class {
    public bool $transactional = true;

    public function up(): void
    {
        AiAgentTool::query()->updateOrCreate(
            ['key' => 'search_in_project'],
            [
                'kind' => 'registered_class',
                'class' => SearchInProjectFactory::class,
                'label' => 'Search in Project',
                'description' => 'Semantic + keyword search within a specific project (parallel multi-query).',
                'parameter_manifest' => (new SearchInProject('_'))->editableParameters(),
                'allowed_sub_agent_types' => null,
                'is_enabled' => true,
            ],
        );
    }

    public function down(): void
    {
        AiAgentTool::query()->where('key', 'search_in_project')->delete();
    }
};
