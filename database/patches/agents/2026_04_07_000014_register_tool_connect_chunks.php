<?php

declare(strict_types=1);

use SimoneBianco\LaravelAiAgents\Models\AiAgentTool;
use SimoneBianco\LaravelRagChunks\AiAgents\Factories\ConnectChunksFactory;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\ConnectChunks;

return new class {
    public bool $transactional = true;

    public function up(): void
    {
        AiAgentTool::query()->updateOrCreate(
            ['key' => 'connect_chunks'],
            [
                'kind' => 'registered_class',
                'class' => ConnectChunksFactory::class,
                'label' => 'Connect Chunks',
                'description' => 'Create a relation (uni- or bidirectional) between two chunks.',
                'parameter_manifest' => (new ConnectChunks())->editableParameters(),
                'allowed_sub_agent_types' => null,
                'is_enabled' => true,
            ],
        );
    }

    public function down(): void
    {
        AiAgentTool::query()->where('key', 'connect_chunks')->delete();
    }
};
