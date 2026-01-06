<?php

namespace SimoneBianco\LaravelRagChunks;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\LaravelPackageTools\Commands\InstallCommand;

class LaravelRagChunksServiceProvider extends PackageServiceProvider
{
    /**
     * Configure the package.
     *
     * @param Package $package
     * @return void
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-rag-chunks')
            ->hasConfigFile('laravel-rag-chunks')
            ->hasCommands([
            ])
            ->hasInstallCommand(function(InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->askToStarRepoOnGitHub('simone-bianco/laravel-rag-chunks');
            });
    }

    /**
     * Register package services.
     *
     * @return void
     */
    public function packageRegistered(): void
    {
    }
}
