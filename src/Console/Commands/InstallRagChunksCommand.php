<?php

namespace SimoneBianco\LaravelRagChunks\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallRagChunksCommand extends Command
{
    protected $signature = 'rag-chunks:install';

    protected $description = 'Install the Laravel RAG Chunks package and publish migrations';

    public function handle(): void
    {
        $this->info('Installing Laravel RAG Chunks...');

        // 1. Config
        $this->call('vendor:publish', [
            '--tag' => 'laravel-rag-chunks-config',
        ]);

        // 2. Migrations Strategy
        $driver = $this->choice(
            'Which database driver are you using for vector storage?',
            ['postgres', 'other'],
            'postgres'
        );

        // Publish Migrations
        $migrationPath = database_path('migrations');
        $now = now();
        
        // 1. Create Documents Table
        $this->publishMigration(
            $driver, 
            'create_documents_table', 
            $migrationPath, 
            $now->format('Y_m_d_His')
        );

        // 2. Create Chunks Table (1 second later to ensure order)
        $this->publishMigration(
            $driver, 
            'create_chunks_table', 
            $migrationPath, 
            $now->addSecond()->format('Y_m_d_His')
        );

        $this->info("Published migrations for driver: {$driver}");
        $this->info('Package installed successfully.');
        $this->info('Please review the configuration file at config/rag_chunks.php');
    }

    protected function publishMigration(string $driver, string $filename, string $migrationPath, string $timestamp): void
    {
        $stubPath = __DIR__ . "/../../../stubs/migrations/{$driver}/{$filename}.php.stub";
        $targetPath = "{$migrationPath}/{$timestamp}_{$filename}.php";

        if (File::exists($targetPath)) {
            $this->warn("Migration {$filename} already exists.");
        } else {
            File::copy($stubPath, $targetPath);
            $this->info("Published migration: {$filename}");
        }
    }
}
