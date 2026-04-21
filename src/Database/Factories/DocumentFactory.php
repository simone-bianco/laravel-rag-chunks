<?php

namespace SimoneBianco\LaravelRagChunks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Project;

class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        $name = $this->faker->words(3, true);

        return [
            'project_id' => Project::factory(),
            'name' => $name,
            'alias' => $this->faker->unique()->slug,
            'extension' => 'txt',
            'enabled' => true,
            'hash' => md5($name),
        ];
    }
}
