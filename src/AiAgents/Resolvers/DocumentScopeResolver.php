<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents\Resolvers;

use SimoneBianco\LaravelAiAgents\Contracts\ScopeBindingResolver;
use SimoneBianco\LaravelAiAgents\DTOs\ScopeBindingSnapshot;
use SimoneBianco\LaravelRagChunks\Models\Document;

final class DocumentScopeResolver implements ScopeBindingResolver
{
    public function resolve(string $scopeKey, array $metadata): ScopeBindingSnapshot
    {
        $document = Document::query()->with('project')->find($scopeKey)
            ?? Document::query()->with('project')->where('alias', $scopeKey)->first();

        if (! $document) {
            return new ScopeBindingSnapshot(false, [], '');
        }

        return new ScopeBindingSnapshot(
            true,
            [
                'id' => (string) $document->id,
                'alias' => (string) $document->alias,
                'name' => (string) $document->name,
                'description' => (string) ($document->description ?? ''),
                'project_alias' => (string) ($document->project?->alias ?? ''),
                'project_name' => (string) ($document->project?->name ?? ''),
            ],
            (string) $document->name,
        );
    }

    public function searchForUi(string $query, int $limit = 20): array
    {
        return Document::query()
            ->with('project')
            ->where('name', 'ILIKE', "%{$query}%")
            ->orWhere('alias', 'ILIKE', "%{$query}%")
            ->limit($limit)
            ->get()
            ->map(fn (Document $d): array => [
                'key' => (string) $d->id,
                'label' => (string) $d->name,
                'sub_label' => (string) $d->alias,
                'fields' => [
                    'id' => (string) $d->id,
                    'alias' => (string) $d->alias,
                    'name' => (string) $d->name,
                    'description' => (string) ($d->description ?? ''),
                    'project_alias' => (string) ($d->project?->alias ?? ''),
                ],
            ])
            ->toArray();
    }

    public function suggestedVariableFields(): array
    {
        return ['id', 'alias', 'name', 'description', 'project_alias', 'project_name'];
    }
}
