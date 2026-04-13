<?php

declare(strict_types=1);

use SimoneBianco\LaravelAiAgents\Models\AiAgentTool;
use SimoneBianco\LaravelRagChunks\AiAgents\Factories\GetChunksByAliasesFactory;

return new class {
    public bool $transactional = true;

    public function up(): void
    {
        AiAgentTool::query()->updateOrCreate(
            ['key' => 'get_chunks_by_aliases'],
            [
                'kind' => 'registered_class',
                'class' => GetChunksByAliasesFactory::class,
                'label' => 'Get Chunks By Aliases',
                'description' => 'Retrieve specific chunks by UUID with neighbors and relations.',
                'parameter_manifest' => (new GetChunksByAliasesFactory())->editableParameters(),
                'allowed_sub_agent_types' => null,
                'is_enabled' => true,
            ],
        );
    }

    public function down(): void
    {
        AiAgentTool::query()->where('key', 'get_chunks_by_aliases')->delete();
    }
};
