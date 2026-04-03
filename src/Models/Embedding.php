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
        $hash = HashService::hash($text);

        $search = static::where('hash', $hash)->first();

        if ($search) {
            return $search->embedding;
        }

        $search = new Embedding([
            'hash' => $hash,
            'embedding' => EmbeddingFactory::make()->embed($text)
        ]);

        $search->save();

        return $search->embedding;
    }

    public static function multiEmbed(array $texts): array
    {
        $embeds = EmbeddingFactory::make()->multiEmbed($texts);

        $dataToInsertByHash = [];
        $now = now();

        foreach ($texts as $index => $text) {
            if (!isset($embeds[$index])) {
                continue;
            }

            $hash = HashService::hash($text);

            if (!isset($dataToInsertByHash[$hash])) {
                $dataToInsertByHash[$hash] = [
                    'id'         => (string) Str::uuid(),
                    'hash'       => $hash,
                    'embedding'  => json_encode($embeds[$index]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $dataToInsert = array_values($dataToInsertByHash);

        if (!empty($dataToInsert)) {
            static::upsert($dataToInsert, ['hash'], ['updated_at']);
        }

        return $embeds;
    }
}
