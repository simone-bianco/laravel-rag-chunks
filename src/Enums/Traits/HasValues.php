<?php

namespace SimoneBianco\LaravelRagChunks\Enums\Traits;

trait HasValues
{
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function strValues(): string
    {
        return implode(', ', self::values());
    }
}
