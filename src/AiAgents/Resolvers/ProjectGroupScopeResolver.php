<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents\Resolvers;

use SimoneBianco\LaravelAiAgents\Contracts\ScopeBindingResolver;
use SimoneBianco\LaravelAiAgents\DTOs\ScopeBindingSnapshot;
use SimoneBianco\LaravelRagChunks\Models\ProjectGroup;

final class ProjectGroupScopeResolver implements ScopeBindingResolver
{
    public function resolve(string $scopeKey, array $metadata): ScopeBindingSnapshot
    {
        $group = ProjectGroup::query()->find($scopeKey)
            ?? ProjectGroup::query()->where('name', $scopeKey)->first();

        if (! $group) {
            return new ScopeBindingSnapshot(false, [], '');
        }

        $allowedAliases = $group->projects()->pluck('alias')->all();

        return new ScopeBindingSnapshot(
            true,
            [
                'id' => (string) $group->id,
                'name' => (string) $group->name,
                'description' => (string) ($group->description ?? ''),
                'allowed_project_aliases' => $allowedAliases,
            ],
            (string) $group->name,
        );
    }

    public function searchForUi(string $query, int $limit = 20): array
    {
        return ProjectGroup::query()
            ->where('name', 'ILIKE', "%{$query}%")
            ->limit($limit)
            ->get()
            ->map(fn (ProjectGroup $g): array => [
                'key' => (string) $g->id,
                'label' => (string) $g->name,
                'sub_label' => (string) ($g->description ?? ''),
                'fields' => [
                    'id' => (string) $g->id,
                    'name' => (string) $g->name,
                    'description' => (string) ($g->description ?? ''),
                ],
            ])
            ->toArray();
    }

    public function suggestedVariableFields(): array
    {
        return ['id', 'name', 'description', 'allowed_project_aliases'];
    }
}
