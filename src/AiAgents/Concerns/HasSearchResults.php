<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Concerns;

use SimoneBianco\LaravelRagChunks\Services\SearchResultService;

trait HasSearchResults
{
    protected function searchResultsActive(): bool
    {
        return $this->historyEnabled === true;
    }

    protected function getRecentSearchResults(string $query, int $count = 5): array
    {
        if (! $this->searchResultsActive()) {
            return [];
        }

        if (trim($query) === '') {
            return [];
        }

        $projectId = property_exists($this, 'scopeProjectId') && is_string($this->scopeProjectId)
            ? $this->scopeProjectId
            : null;

        return SearchResultService::make()->getRecent($query, $count, $projectId);
    }
}
