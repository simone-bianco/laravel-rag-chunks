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

        $stubName = match ($driver) {
            'postgres' => 'postgres_create_chunks_table.php.stub',
            default => 'generic_create_chunks_table.php.stub',
        };

        $this->publishMigration($stubName);

        $this->info('Package installed successfully.');
        $this->info('Please review the configuration file at config/laravel-rag-chunks.php');
    }

    protected function publishMigration(string $stubName): void
    {
        $stubPath = __DIR__ . '/../../../stubs/migrations/' . $stubName;
        $timestamp = date('Y_m_d_His');
        $migrationFileName = "{$timestamp}_create_chunks_table.php";
        $destinationPath = database_path("migrations/{$migrationFileName}");

        if (File::exists($destinationPath)) {
            $this->warn("Migration file already exists: {$migrationFileName}");
            return;
        }

        File::copy($stubPath, $destinationPath);
        $this->info("Published migration: {$migrationFileName}");
    }
}
