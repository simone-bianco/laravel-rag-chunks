<?php

namespace SimoneBianco\LaravelRagChunks;

use SimoneBianco\LaravelRagChunks\Drivers\Embedding\Contracts\EmbeddingDriverInterface;
use SimoneBianco\LaravelRagChunks\Drivers\Embedding\OpenaiEmbeddingDriver;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelRagChunksServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-rag-chunks')
            ->hasConfigFile('rag_chunks')
            ->hasMigrations(['create_searches_table', 'add_description_embedding_to_documents_table'])
            ->runsMigrations()
            ->hasCommand(Console\Commands\InstallRagChunksCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton('rag-chunks-hash', function () {
            return new Services\HashService();
        });

        $this->app->bind(EmbeddingDriverInterface::class, function () {
            return Factories\EmbeddingFactory::make();
        });
    }

    public function packageBooted(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
