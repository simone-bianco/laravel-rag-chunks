<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents\Types;

use SimoneBianco\LaravelAiAgents\DTOs\AgentDefinitionData;
use SimoneBianco\LaravelAiAgents\Models\AiAgent;

abstract class AgentTypeDefinition
{
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    /**
     * @return array<int, string>
     */
    abstract public function allowedToolKeys(): array;

    /**
     * @return array<int, string>
     */
    abstract public function allowedSubAgentTypes(): array;

    /**
     * @return array<int, string>
     */
    abstract public function formSections(): array;

    abstract public function defaultModel(): string;

    abstract public function defaultStreamingMode(): string;

    /**
     * @return array<string, mixed>|null
     */
    abstract public function buildResponseSchema(AiAgent $agent): ?array;

    abstract public function validateDefinition(AgentDefinitionData $data): void;
}
