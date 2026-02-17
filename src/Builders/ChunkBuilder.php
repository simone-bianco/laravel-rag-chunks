<?php

namespace SimoneBianco\LaravelRagChunks\Builders;

use Illuminate\Database\Eloquent\Builder;
use SimoneBianco\LaravelRagChunks\Enums\TagFilterMode;

class ChunkBuilder extends Builder
{
    public function whereBasicFilters(?array $chunksIds, ?string $textSearch, ?array $keywordsSearch): self
    {
        return $this
            ->when(!empty($chunksIds), fn($q) => $q->whereIn('id', $chunksIds))
            ->when(!empty($keywordsSearch), function ($q) use ($keywordsSearch) {
                foreach ($keywordsSearch as $keyword) {
                    $q->where('content', 'ilike', "%{$keyword}%");
                }
                return $q;
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
        ?array $questionsVector,
        ?array $tagsVector,
        ?float $weightContent,
        ?float $weightQuestions,
        ?float $weightTags
    ): self {
        $weightContent = $weightContent ?? config('rag_chunks.semantic_weights.content', 0.5);
        $weightQuestions = $weightQuestions ?? config('rag_chunks.semantic_weights.questions', 0.3);
        $weightTags = $weightTags ?? config('rag_chunks.semantic_weights.tags', 0.2);

        $contentVectorStr   = $contentVector   ? '[' . implode(',', $contentVector) . ']'   : null;
        $questionsVectorStr = $questionsVector ? '[' . implode(',', $questionsVector) . ']' : null;
        $tagsVectorStr      = $tagsVector      ? '[' . implode(',', $tagsVector) . ']'      : null;

        $vectors = array_filter([
            'content'   => $contentVectorStr,
            'questions' => $questionsVectorStr,
            'tags'      => $tagsVectorStr,
        ]);

        if (count($vectors) === 0) {
            return $this;
        }

        // Normalize weights based on which vectors are present
        $totalWeight = 0;
        $weights = [];
        if ($contentVectorStr)   { $weights['content']   = $weightContent;   $totalWeight += $weightContent; }
        if ($questionsVectorStr) { $weights['questions'] = $weightQuestions; $totalWeight += $weightQuestions; }
        if ($tagsVectorStr)      { $weights['tags']      = $weightTags;      $totalWeight += $weightTags; }

        // Normalize weights to sum to 1.0
        if ($totalWeight > 0) {
            foreach ($weights as $key => $weight) {
                $weights[$key] = $weight / $totalWeight;
            }
        }

        // Build the combined score SQL
        $scoreParts = [];
        $bindings = [];

        if ($contentVectorStr) {
            $scoreParts[] = "( (1 - (embedding <=> ?)) * {$weights['content']} )";
            $bindings[] = $contentVectorStr;
        }
        if ($questionsVectorStr) {
            $scoreParts[] = "( (1 - (questions_embedding <=> ?)) * {$weights['questions']} )";
            $bindings[] = $questionsVectorStr;
        }
        if ($tagsVectorStr) {
            $scoreParts[] = "( (1 - (tags_embedding <=> ?)) * {$weights['tags']} )";
            $bindings[] = $tagsVectorStr;
        }

        $scoreSql = implode(' + ', $scoreParts);

        $query = $this->selectRaw("($scoreSql) as combined_score", $bindings);

        // Add individual similarity scores
        if ($contentVectorStr) {
            $query->selectRaw('1 - (embedding <=> ?) as content_similarity', [$contentVectorStr]);
        }
        if ($questionsVectorStr) {
            $query->selectRaw('1 - (questions_embedding <=> ?) as questions_similarity', [$questionsVectorStr]);
        }
        if ($tagsVectorStr) {
            $query->selectRaw('1 - (tags_embedding <=> ?) as tags_similarity', [$tagsVectorStr]);
        }

        return $query->orderByRaw("($scoreSql) DESC", $bindings);
    }
}
