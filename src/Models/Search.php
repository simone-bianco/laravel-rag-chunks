<?php

namespace SimoneBianco\LaravelRagChunks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use SimoneBianco\LaravelRagChunks\Enums\ChunkModel;
use SimoneBianco\LaravelRagChunks\Facades\HashService;
use SimoneBianco\LaravelRagChunks\Factories\EmbeddingFactory;
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;

class Search extends Model
{
    use HasUuids;

    protected ChunkModel $driver;

    public function __construct(array $attributes = [])
    {
        $this->driver = config('rag_chunks.driver', ChunkModel::POSTGRES);
        parent::__construct($attributes);
    }

    protected $fillable = [
        'text',
        'hash',
        'embedding'
    ];

    protected function casts()
    {
        return [
            'embedding' => $this->driver === ChunkModel::POSTGRES ? VectorArray::class : 'array',
        ];
    }

    public static function embed(string $text): array
    {
        $hash = HashService::hash($text);

        $search = static::where('hash', $hash)->first();

        if ($search) {
            return $search;
        }

        $search = new Search([
            'text' => $text,
            'hash' => $hash,
            'embedding' => EmbeddingFactory::make()->embed($text)
        ]);

        $search->save();

        return $search->embedding;
    }
}
