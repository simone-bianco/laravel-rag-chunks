<?php

namespace SimoneBianco\LaravelRagChunks\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SimoneBianco\LaravelRagChunks\LaravelRagChunksServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

        // Setup default encryption key for testing
        $app['config']->set('app.key', 'base64:6Cu/ozj4w0CjZ+h4F1ZO0a4Yy7d5Zc7eX0y0z1a2b3c=');

        // Setup Package Config
        $app['config']->set('rag_chunks.driver', \SimoneBianco\LaravelRagChunks\Enums\ChunkingDriver::POSTGRES);
        $app['config']->set('rag_chunks.embedding', \SimoneBianco\LaravelRagChunks\Enums\EmbeddingDriver::OPENAI);
        $app['config']->set('rag_chunks.models', [
            'chunk' => \SimoneBianco\LaravelRagChunks\Tests\Models\TestChunk::class
        ]);
        $app['config']->set('rag_chunks.embedders', [
            \SimoneBianco\LaravelRagChunks\Enums\EmbeddingDriver::OPENAI->value => \SimoneBianco\LaravelRagChunks\Services\Embedding\OpenaiEmbeddingDriver::class
        ]);
    }

    protected function defineDatabaseMigrations()
    {
        // Load the generic migration for testing purposes
        $migration = include __DIR__.'/../stubs/migrations/generic_create_chunks_table.php.stub';
        $migration->up();
    }
}
