<?php

namespace SimoneBianco\LaravelRagChunks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SimoneBianco\LaravelRagChunks\Models\Project;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name,
            'alias' => $this->faker->slug,
            'settings' => [],
        ];
    }
}
