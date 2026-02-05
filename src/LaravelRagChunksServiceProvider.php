<?php


namespace SimoneBianco\LaravelRagChunks;

use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Console\Commands\InstallRagChunksCommand;
use SimoneBianco\LaravelRagChunks\Console\Commands\TestDispatchParsingCommand;
use SimoneBianco\LaravelRagChunks\Console\Commands\TestPollParsingCommand;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\FileService;
use SimoneBianco\LaravelRagChunks\Services\HashService;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelRagChunksServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-rag-chunks')
            ->hasConfigFile('rag_chunks')
            ->hasCommand(InstallRagChunksCommand::class)
            ->hasCommand(TestDispatchParsingCommand::class)
            ->hasCommand(TestPollParsingCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->bind('rag-chunks-hash', function () {
            return new HashService();
        });

        $this->app->bind('rag-chunks-file', function () {
            return new FileService();
        });
    }

    public function packageBooted(): void
    {
        Process::resolveRelationUsing('document', function ($process) {
            return $process->belongsTo(Document::class, 'processable_id')
                ->where('processable_type', Document::class);
        });
    }
}
