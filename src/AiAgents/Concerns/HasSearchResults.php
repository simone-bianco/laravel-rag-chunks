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

        return SearchResultService::make()->getRecent($query, $count);
    }
}
