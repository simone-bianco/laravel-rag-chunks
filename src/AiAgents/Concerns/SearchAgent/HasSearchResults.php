<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Concerns\SearchAgent;

use Illuminate\Support\Facades\Log;
use SimoneBianco\LaravelRagChunks\Services\SearchResultService;

trait HasSearchResults
{
    protected function searchResultsActive(): bool
    {
        return ($this->historyEnabled ?? false) === true;
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
        $minMatchRatio = $queryTokens === [] ? 1.0 : $this->minMatchRatioForTokens($queryTokens);

        $accepted = [];
        $rejected = [];

        foreach ($recent as $entry) {
            $entryId = is_string($entry['id'] ?? null) ? $entry['id'] : '?';
            $similarity = (float) ($entry['similarity'] ?? 0.0);

            // High semantic similarity → accept regardless of token overlap (cross-language rescue)
            if ($similarity >= 0.80) {
                $accepted[] = $entry;
                continue;
            }

            // Token overlap rule
            $historyQuery = is_string($entry['query'] ?? null) ? (string) $entry['query'] : '';
            $historyNotes = is_string($entry['notes'] ?? null) ? (string) $entry['notes'] : '';
            $historyText = trim($historyQuery . ' ' . $historyNotes);
            $historyTokens = $this->tokenizeForHistoryMatch($historyText);

            if ($historyTokens !== [] && $queryTokens !== []) {
                $overlap = array_values(array_intersect($queryTokens, $historyTokens));
                $matchRatio = count($overlap) / count($queryTokens);
                if ($matchRatio >= $minMatchRatio) {
                    $accepted[] = $entry;
                    continue;
                }
                $reason = sprintf('tokens=%.2f/%s', round($matchRatio, 2), round($minMatchRatio, 2));
            } else {
                $reason = 'no tokens';
            }

            if (($reason ?? '') === '') {
                $reason = sprintf('sim=%.3f', $similarity);
            }

            $rejected[] = ['id' => $entryId, 'reason' => $reason];
        }

        if ($rejected !== []) {
            Log::channel('search')->info('[HasSearchResults] Pre-lookup filter', [
                'search_query' => mb_substr($query, 0, 200),
                'query_tokens' => $queryTokens,
                'min_match_ratio' => round($minMatchRatio, 2),
                'accepted_count' => count($accepted),
                'rejected_count' => count($rejected),
                'rejected' => $rejected,
            ]);
        }

        return array_values($accepted);
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

    /**
     * Removed — was only used by the removed expandChunksForExplicitSessionQuery.
     * @deprecated
     */
}
