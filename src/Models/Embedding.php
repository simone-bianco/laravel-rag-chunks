<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use SimoneBianco\LaravelRagChunks\Facades\HashService;
use SimoneBianco\LaravelRagChunks\Factories\EmbeddingFactory;
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;

class Embedding extends Model
{
    use HasUuids;

    protected $fillable = [
        'hash',
        'embedding'
    ];

    protected function casts()
    {
        return [
            'embedding' => VectorArray::class
        ];
    }

    public static function embed(string $text): array
    {
        return static::multiEmbed([$text])[0] ?? [];
    }

    /**
     * Embed many texts in one driver call while preserving input keys and using the local hash cache.
     *
     * @param array<int|string, string> $texts
     * @return array<int|string, array>
     */
    public static function multiEmbed(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $hashesByInputKey = [];
        $missingTextsByHash = [];

        foreach ($texts as $key => $text) {
            $hash = HashService::hash($text);
            $hashesByInputKey[$key] = $hash;
            $missingTextsByHash[$hash] ??= $text;
        }

        $vectorsByHash = static::query()
            ->whereIn('hash', array_values(array_unique($hashesByInputKey)))
            ->get(['hash', 'embedding'])
            ->mapWithKeys(static fn (Embedding $embedding): array => [
                $embedding->hash => $embedding->embedding,
            ])
            ->all();

        $missingTextsByHash = array_diff_key($missingTextsByHash, $vectorsByHash);

        if (! empty($missingTextsByHash)) {
            $missingHashes = array_keys($missingTextsByHash);
            $missingTexts = array_values($missingTextsByHash);
            $newVectors = EmbeddingFactory::make()->multiEmbed($missingTexts);

            $dataToInsertByHash = [];
            $now = now();

            foreach ($missingHashes as $index => $hash) {
                if (! isset($newVectors[$index])) {
                    continue;
                }

                $vectorsByHash[$hash] = $newVectors[$index];

                $dataToInsertByHash[$hash] = [
                    'id'         => (string) Str::uuid(),
                    'hash'       => $hash,
                    'embedding'  => json_encode($newVectors[$index]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $dataToInsert = array_values($dataToInsertByHash);

            if (! empty($dataToInsert)) {
                static::upsert($dataToInsert, ['hash'], ['updated_at']);
            }
        }

        $embeds = [];
        foreach ($hashesByInputKey as $key => $hash) {
            if (isset($vectorsByHash[$hash])) {
                $embeds[$key] = $vectorsByHash[$hash];
            }
        }

        return $embeds;
    }
}
