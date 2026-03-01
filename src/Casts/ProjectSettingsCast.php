<?php

namespace SimoneBianco\LaravelRagChunks\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use SimoneBianco\LaravelRagChunks\DTOs\ProjectSettings;

class ProjectSettingsCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ProjectSettings
    {
        if ($value === null) {
            return new ProjectSettings();
        }

        $data = is_string($value) ? json_decode($value, true) : (array) $value;

        return ProjectSettings::fromArray($data ?? []);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value instanceof ProjectSettings) {
            return json_encode($value->toArray());
        }

        if (is_array($value)) {
            return json_encode(ProjectSettings::fromArray($value)->toArray());
        }

        return $value;
    }
}
