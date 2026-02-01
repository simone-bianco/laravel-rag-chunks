<?php

namespace SimoneBianco\LaravelRagChunks;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelRagChunksServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-rag-chunks')
            ->hasConfigFile('rag_chunks')
            ->hasMigration('2026_02_01_141016_add_order_to_chunks_table')
            ->hasCommand(\SimoneBianco\LaravelRagChunks\Console\Commands\InstallRagChunksCommand::class)
            ->hasCommand(\SimoneBianco\LaravelRagChunks\Console\Commands\TestDispatchParsingCommand::class)
            ->hasCommand(\SimoneBianco\LaravelRagChunks\Console\Commands\TestPollParsingCommand::class);
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
