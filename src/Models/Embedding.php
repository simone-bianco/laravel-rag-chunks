<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use SimoneBianco\LaravelRagChunks\Facades\HashService;
use SimoneBianco\LaravelRagChunks\Factories\EmbeddingFactory;
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;

class Embedding extends Model
{
    use HasUuids;

    protected $fillable = [
        'text',
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
            'text' => $text,
            'hash' => $hash,
            'embedding' => EmbeddingFactory::make()->embed($text)
        ]);

        $search->save();

        return $search->embedding;
    }
}
