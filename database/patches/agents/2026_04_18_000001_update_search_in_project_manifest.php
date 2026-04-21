<?php

declare(strict_types=1);

use SimoneBianco\LaravelAiAgents\Models\AiAgentTool;
use SimoneBianco\LaravelRagChunks\AiAgents\Factories\SearchInProjectFactory;

return new class {
    public bool $transactional = true;

    public function up(): void
    {
        AiAgentTool::query()
            ->where('key', 'search_in_project')
            ->update([
                'class'              => SearchInProjectFactory::class,
                'parameter_manifest' => json_encode([]),
            ]);
    }

    public function down(): void
    {
        // no-op
    }
};
