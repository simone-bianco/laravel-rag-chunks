<?php

namespace SimoneBianco\LaravelRagChunks\Builders;

use Illuminate\Database\Eloquent\Builder;
use SimoneBianco\LaravelRagChunks\Enums\TagFilterMode;

class ChunkBuilder extends Builder
{
    public function whereKeywords(?array $keywords): self
    {
        return $this->when(!empty($keywords), function ($q) use ($keywords) {
            $q->whereJsonOverlaps('keywords', $keywords);
        });
    }

    public function whereAliases(?array $docAliases, ?array $projAliases): self
    {
        return $this
            ->when(!empty($docAliases), fn($q) =>
            $q->whereHas('document', fn($sq) => $sq->whereIn('alias', $docAliases))
            )
            ->when(!empty($projAliases), fn($q) =>
            $q->whereHas('document.project', fn($sq) => $sq->whereIn('alias', $projAliases))
            );
    }

    public function whereTagFilters($tagFilters): self
    {
        return $this->when($tagFilters && $tagFilters->isNotEmpty(), function ($q) use ($tagFilters) {
            $q->whereHas('document', function ($docQuery) use ($tagFilters) {
                foreach ($tagFilters as $filter) {
                    $method = $filter->rule_filter === TagFilterMode::ALL ? 'withAllTags' : 'withAnyTags';
                    $docQuery->{$method}([$filter->tag], $filter->type);
                }
            });
        });
    }

    public function withHybridRanking(
        ?array $contentVector,
        ?array $tagsVector,
        ?float $weightContent,
        ?float $weightTags
    ): self {
        $weightContent = $weightContent ?? config('rag_chunks.semantic_weights.content', 0.7);
        $weightTags = $weightTags ?? config('rag_chunks.semantic_weights.semantic_tags', 0.3);

        $contentVectorStr = $contentVector ? '[' . implode(',', $contentVector) . ']' : null;
        $tagsVectorStr    = $tagsVector    ? '[' . implode(',', $tagsVector) . ']'    : null;

        if ($contentVectorStr && $tagsVectorStr) {
            $scoreSql = "( (1 - (embedding <=> ?)) * {$weightContent} ) + ( (1 - (semantic_tags_embedding <=> ?)) * {$weightTags} )";

            return $this->selectRaw("$scoreSql as combined_score", [$contentVectorStr, $tagsVectorStr])
                ->selectRaw('1 - (embedding <=> ?) as content_similarity', [$contentVectorStr])
                ->selectRaw('1 - (semantic_tags_embedding <=> ?) as semantic_tags_similarity', [$tagsVectorStr])
                ->orderByRaw("$scoreSql DESC", [$contentVectorStr, $tagsVectorStr]);
        }

        if ($contentVectorStr) {
            return $this->nearestNeighbors('embedding', $contentVectorStr, 'cosine')
                ->selectRaw('1 - (embedding <=> ?) as content_similarity', [$contentVectorStr]);
        }

        if ($tagsVectorStr) {
            return $this->nearestNeighbors('semantic_tags_embedding', $tagsVectorStr, 'cosine')
                ->selectRaw('1 - (semantic_tags_embedding <=> ?) as semantic_tags_similarity', [$tagsVectorStr]);
        }

        return $this;
    }
}
