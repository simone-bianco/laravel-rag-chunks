<?php

declare(strict_types=1);

use SimoneBianco\LaravelAiAgents\Models\AiAgentTool;
use SimoneBianco\LaravelRagChunks\AiAgents\Factories\SearchInAllowedProjectsFactory;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchInAllowedProjects;

return new class {
    public bool $transactional = true;

    public function up(): void
    {
        AiAgentTool::query()->updateOrCreate(
            ['key' => 'search_in_allowed_projects'],
            [
                'kind' => 'registered_class',
                'class' => SearchInAllowedProjectsFactory::class,
                'label' => 'Search in Allowed Projects',
                'description' => 'Search within an allowlist of project aliases (multi-project chat).',
                'parameter_manifest' => (new SearchInAllowedProjects([]))->editableParameters(),
                'allowed_sub_agent_types' => null,
                'is_enabled' => true,
            ],
        );
    }

    public function down(): void
    {
        AiAgentTool::query()->where('key', 'search_in_allowed_projects')->delete();
    }
};
