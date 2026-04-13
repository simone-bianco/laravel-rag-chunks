<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents\Types;

use InvalidArgumentException;

final class AgentTypeRegistry
{
    /** @var array<string, class-string<AgentTypeDefinition>> */
    private static array $types = [];

    /**
     * @param class-string<AgentTypeDefinition> $definitionClass
     */
    public static function register(AgentType $type, string $definitionClass): void
    {
        self::$types[$type->value] = $definitionClass;
    }

    public static function get(AgentType|string $type): AgentTypeDefinition
    {
        $key = $type instanceof AgentType ? $type->value : $type;

        if (! isset(self::$types[$key])) {
            throw new InvalidArgumentException("Agent type '{$key}' not registered");
        }

        /** @var AgentTypeDefinition */
        return app(self::$types[$key]);
    }

    public static function has(AgentType|string $type): bool
    {
        $key = $type instanceof AgentType ? $type->value : $type;

        return isset(self::$types[$key]);
    }

    /**
     * @return array<string, AgentTypeDefinition>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::$types as $key => $class) {
            /** @var AgentTypeDefinition $instance */
            $instance = app($class);
            $out[$key] = $instance;
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function toApiArray(): array
    {
        $out = [];
        foreach (self::all() as $def) {
            $out[] = [
                'key' => $def->key(),
                'label' => $def->label(),
                'description' => $def->description(),
                'allowed_tool_keys' => $def->allowedToolKeys(),
                'allowed_sub_agent_types' => $def->allowedSubAgentTypes(),
                'form_sections' => $def->formSections(),
                'default_model' => $def->defaultModel(),
                'default_streaming_mode' => $def->defaultStreamingMode(),
            ];
        }

        return $out;
    }

    public static function reset(): void
    {
        self::$types = [];
    }
}
