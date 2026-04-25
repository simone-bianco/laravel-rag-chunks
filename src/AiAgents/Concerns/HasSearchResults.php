<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Concerns;

use InvalidArgumentException;
use SimoneBianco\LaravelAiAgents\Models\AiAgent;
use SimoneBianco\LaravelRagChunks\Services\SearchResultService;

trait HasSearchResults
{
    protected function searchResultsActive(): bool
    {
        return $this->callingAgentId !== null && $this->historyEnabled === true;
    }

    protected function getRecentSearchResults(string $query, int $count = 5): array
    {
        if (! $this->searchResultsActive()) {
            return [];
        }

        if (trim($query) === '') {
            return [];
        }

        $agent = AiAgent::find($this->callingAgentId);
        if (! $agent) {
            throw new InvalidArgumentException("Agent {$this->callingAgentId} not found for search-results lookup");
        }

        if (! is_string($this->historyProjectId ?? null) || trim((string) $this->historyProjectId) === '') {
            return [];
        }

        return SearchResultService::forAgent($agent)->getRecent($query, (string) $this->historyProjectId, $count);
    }

}
