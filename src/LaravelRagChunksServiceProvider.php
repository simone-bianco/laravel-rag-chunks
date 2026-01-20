<?php

namespace SimoneBianco\LaravelRagChunks;

use SimoneBianco\LaravelRagChunks\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelRagChunksServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-rag-chunks')
            ->hasConfigFile('rag_chunks')
            ->hasCommand(\SimoneBianco\LaravelRagChunks\Console\Commands\InstallRagChunksCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->bind('rag-chunks-hash', function () {
            return new \SimoneBianco\LaravelRagChunks\Services\HashService();
        });

        $this->app->bind('rag-chunks-file', function () {
            return new \SimoneBianco\LaravelRagChunks\Services\FileService();
        });
    }
}
