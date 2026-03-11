<?php

namespace SimoneBianco\LaravelRagChunks\Builders;

use Illuminate\Database\Eloquent\Builder;
use SimoneBianco\LaravelRagChunks\Enums\TagFilterMode;

class ChunkBuilder extends Builder
{
    public function whereDocumentId(?string $documentId): self
    {
        return $this->when($documentId, fn ($q) => $q->where('document_id', $documentId));
    }

    public function whereContentLength(?int $min = null, ?int $max = null): self
    {
        return $this
            ->when($min !== null && $min > 0, fn ($q) => $q->whereRaw('LENGTH(content) >= ?', [$min]))
            ->when($max !== null && $max > 0, fn ($q) => $q->whereRaw('LENGTH(content) <= ?', [$max]));
    }

    public function whereDirty(?bool $dirty): self
    {
        return $this->when($dirty, fn ($q) => $q->where(function ($q) {
            $q->where('is_content_dirty', true)
                ->orWhere('is_tags_dirty', true)
                ->orWhere('is_questions_dirty', true)
                ->orWhereNull('embedding')
                ->orWhereNull('tags_embedding')
                ->orWhereNull('questions_embedding');
        }));
    }

    public function whereHasEmbedding(?bool $has): self
    {
        if ($has === null) {
            return $this;
        }

        return $has
            ? $this->whereNotNull('embedding')
            : $this->whereNull('embedding');
    }

    public function whereHasImage(?bool $has): self
    {
        if ($has === null) {
            return $this;
        }

        if ($has) {
            return $this->where(function ($q) {
                $q->where('is_image', true)
                    ->orWhereHas('dedupMedia');
            });
        }

        return $this->where(function ($q) {
            $q->where('is_image', false)
                ->orWhereNull('is_image');
        })->whereDoesntHave('dedupMedia');
    }

    public function whereHasRelations(?bool $has): self
    {
        if ($has === null) {
            return $this;
        }

        if ($has) {
            return $this->where(function ($q) {
                $q->whereHas('outgoingRelations')
                    ->orWhereHas('incomingRelations');
            });
        }

        return $this
            ->whereDoesntHave('outgoingRelations')
            ->whereDoesntHave('incomingRelations');
    }

    public function whereHasChapter(?bool $has): self
    {
        if ($has === null) {
            return $this;
        }

        return $has
            ? $this->whereNotNull('chapter')->where('chapter', '!=', '')
            : $this->where(function ($q) {
                $q->whereNull('chapter')
                    ->orWhere('chapter', '');
            });
    }

    /**
     * @param  string[]|null  $chapters
     */
    public function whereChapters(?array $chapters): self
    {
        return $this->when(! empty($chapters), fn ($q) => $q->whereIn('chapter', $chapters));
    }

    /**
     * Filter by chunk-level tags (not document tags).
     *
     * @param  array<string, int[]>|null  $tagGroups  tagTypeAlias => selectedTagIds
     */
    public function whereChunkTags(?array $tagGroups): self
    {
        return $this->when(!empty($tagGroups), function ($q) use ($tagGroups) {
            foreach ($tagGroups as $tagIds) {
                if (!empty($tagIds)) {
                    $q->whereHas('tags', fn ($tq) => $tq->whereIn('id', $tagIds));
                }
            }
            return $q;
        });
    }

    public function whereKeywordSearch(?string $text, bool $caseSensitive = false): self
    {
        return $this->when(!empty($text), function ($q) use ($text, $caseSensitive) {
            $operator = $caseSensitive ? 'LIKE' : 'ILIKE';
            $q->whereRaw("content {$operator} ?", ['%' . $text . '%']);
        });
    }

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
