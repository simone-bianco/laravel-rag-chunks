<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents\Resolvers;

use SimoneBianco\LaravelAiAgents\Contracts\ScopeBindingResolver;
use SimoneBianco\LaravelAiAgents\DTOs\ScopeBindingSnapshot;
use SimoneBianco\LaravelRagChunks\Models\Project;

final class ProjectScopeResolver implements ScopeBindingResolver
{
    public function resolve(string $scopeKey, array $metadata): ScopeBindingSnapshot
    {
        $isUuid = (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $scopeKey,
        );

        $project = $isUuid
            ? Project::query()->whereKey($scopeKey)->first()
            : null;

        if (! $project) {
            $project = Project::query()->where('alias', $scopeKey)->first();
        }

        if (! $project) {
            return new ScopeBindingSnapshot(false, [], '');
        }

        return new ScopeBindingSnapshot(
            true,
            [
                'id' => (string) $project->id,
                'alias' => (string) $project->alias,
                'name' => (string) $project->name,
                'description' => (string) ($project->description ?? ''),
            ],
            (string) $project->name,
        );
    }

    public function searchForUi(string $query, int $limit = 20): array
    {
        return Project::query()
            ->where('name', 'ILIKE', "%{$query}%")
            ->orWhere('alias', 'ILIKE', "%{$query}%")
            ->limit($limit)
            ->get()
            ->map(fn (Project $p): array => [
                'key' => (string) $p->alias,
                'label' => (string) $p->name,
                'sub_label' => (string) $p->alias,
                'fields' => [
                    'id' => (string) $p->id,
                    'alias' => (string) $p->alias,
                    'name' => (string) $p->name,
                    'description' => (string) ($p->description ?? ''),
                ],
            ])
            ->toArray();
    }

    public function suggestedVariableFields(): array
    {
        return ['alias', 'description'];
    }
}
