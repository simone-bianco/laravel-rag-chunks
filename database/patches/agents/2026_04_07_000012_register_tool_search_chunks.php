<?php

declare(strict_types=1);

use SimoneBianco\LaravelAiAgents\Models\AiAgentTool;
use SimoneBianco\LaravelRagChunks\AiAgents\Factories\SearchChunksFactory;

return new class {
    public bool $transactional = true;

    public function up(): void
    {
        AiAgentTool::query()->updateOrCreate(
            ['key' => 'search_chunks'],
            [
                'kind' => 'registered_class',
                'class' => SearchChunksFactory::class,
                'label' => 'Search Chunks',
                'description' => 'Hybrid RAG search (semantic + keyword + tag filters) on chunks.',
                'parameter_manifest' => (new SearchChunksFactory())->editableParameters(),
                'allowed_sub_agent_types' => null,
                'is_enabled' => true,
            ],
        );
    }

    public function down(): void
    {
        AiAgentTool::query()->where('key', 'search_chunks')->delete();
    }
};
