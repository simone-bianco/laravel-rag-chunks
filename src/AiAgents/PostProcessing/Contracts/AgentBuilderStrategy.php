<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\PostProcessing\Contracts;

interface AgentBuilderStrategy
{
    public function buildSchemaProperties(array $chunksByKey): array;
    public function buildPrompt(array $chunksByKey): string;
}
