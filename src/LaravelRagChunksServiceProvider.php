<?php

namespace SimoneBianco\LaravelRagChunks;

use SimoneBianco\LaravelRagChunks\Services\Embedding\Contracts\EmbeddingDriverInterface;
use SimoneBianco\LaravelRagChunks\Services\Embedding\OpenaiEmbeddingDriver;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelRagChunksServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-rag-chunks')
            ->hasConfigFile('rag_chunks')
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
}
