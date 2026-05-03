<?php

namespace SimoneBianco\LaravelRagChunks\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SimoneBianco\LaravelRagChunks\LaravelRagChunksServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SimoneBianco\LaravelRagChunks\Enums\EmbeddingDriver;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Services\Embedding\OpenaiEmbeddingDriver;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelRagChunksServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'EmbeddingFactory' => \SimoneBianco\LaravelRagChunks\Factories\EmbeddingFactory::class,
            'HashService' => \SimoneBianco\LaravelRagChunks\Facades\HashService::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Setup default encryption key for testing
        $app['config']->set('app.key', 'base64:6Cu/ozj4w0CjZ+h4F1ZO0a4Yy7d5Zc7eX0y0z1a2b3c=');

        // Setup Package Config
        $app['config']->set('rag_chunks.embedding', EmbeddingDriver::OPENAI->value);
        $app['config']->set('rag_chunks.models', [
            'chunk' => Chunk::class,
        ]);
        $app['config']->set('rag_chunks.embedders', [
            EmbeddingDriver::OPENAI->value => OpenaiEmbeddingDriver::class,
        ]);
    }

    protected function defineDatabaseMigrations()
    {
        // Load the generic migration for testing purposes
        $migrationProjs = include __DIR__.'/../stubs/migrations/generic/create_projects_table.php.stub';
        $migrationProjs->up();
        $migrationDocs = include __DIR__.'/../stubs/migrations/generic/create_documents_table.php.stub';
        $migrationDocs->up();
        $migrationProjectGroups = include __DIR__.'/../stubs/migrations/generic/create_project_groups_table.php.stub';
        $migrationProjectGroups->up();
        $migrationGroupProject = include __DIR__.'/../stubs/migrations/generic/create_group_project_table.php.stub';
        $migrationGroupProject->up();
        $migration = include __DIR__.'/../stubs/migrations/generic/create_chunks_table.php.stub';
        $migration->up();
    }
}
