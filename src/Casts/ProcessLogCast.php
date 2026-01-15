<?php

namespace SimoneBianco\LaravelRagChunks\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use SimoneBianco\LaravelRagChunks\Values\LogCollection;
use SimoneBianco\LaravelRagChunks\Values\LogEntry;

class ProcessLogCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): LogCollection
    {
        if (!$value) {
            return new LogCollection();
        }

        $data = json_decode($value, true);
        if (!is_array($data)) {
            return new LogCollection();
        }

        $entries = array_map(function ($item) {
            return new LogEntry(
                $item['severity'],
                $item['content'],
                Carbon::parse($item['timestamp']),
                $item['context'] ?? []
            );
        }, $data);

        return new LogCollection($entries);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if ($value instanceof LogCollection) {
            return json_encode($value->toArray());
        }

        return json_encode($value);
    }
}
