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

        $recent = SearchResultService::make()->getRecent($query, $count, $projectId);

        return $this->filterRecentSearchResultsByQueryMatch($query, $recent);
    }

    /**
     * @param array<int, array<string, mixed>> $recent
     * @return array<int, array<string, mixed>>
     */
    protected function filterRecentSearchResultsByQueryMatch(string $query, array $recent): array
    {
        if ($recent === []) {
            return $recent;
        }

        $queryTokens = $this->tokenizeForHistoryMatch($query);
        if ($queryTokens === []) {
            return $recent;
        }

        $minMatchRatio = $this->minMatchRatioForTokens($queryTokens);
        $querySession = $this->extractSessionNumber($query);

        return array_values(array_filter($recent, function (array $entry) use ($queryTokens, $minMatchRatio, $querySession): bool {
            $historyQuery = is_string($entry['query'] ?? null) ? (string) $entry['query'] : '';
            $historyNotes = is_string($entry['notes'] ?? null) ? (string) $entry['notes'] : '';

            $historyText = trim($historyQuery . ' ' . $historyNotes);
            $historyTokens = $this->tokenizeForHistoryMatch($historyText);
            if ($historyTokens === []) {
                return false;
            }

            $overlap = array_values(array_intersect($queryTokens, $historyTokens));
            $matchRatio = count($queryTokens) > 0
                ? (count($overlap) / count($queryTokens))
                : 0.0;

            if ($matchRatio < $minMatchRatio) {
                return false;
            }

            if ($querySession === null) {
                return true;
            }

            return $this->extractSessionNumber($historyText) === $querySession;
        }));
    }

    /**
     * @return array<int, string>
     */
    protected function tokenizeForHistoryMatch(string $text): array
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return [];
        }

        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized);
        if (! is_string($normalized) || trim($normalized) === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', $normalized) ?: [];

        return array_values(array_unique(array_filter($parts, static fn (string $token): bool => $token !== '')));
    }

    /**
     * @param array<int, string> $queryTokens
     */
    protected function minMatchRatioForTokens(array $queryTokens): float
    {
        return count($queryTokens) >= 4 ? 0.6 : 1.0;
    }

    protected function extractSessionNumber(string $text): ?int
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/\bsession(?:e)?\s*[-:]?\s*(\d+)\b/u', $normalized, $matches) !== 1) {
            return null;
        }

        $value = (int) ($matches[1] ?? 0);

        return $value > 0 ? $value : null;
    }
}
