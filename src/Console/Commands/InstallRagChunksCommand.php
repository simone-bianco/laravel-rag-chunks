<?php

namespace SimoneBianco\LaravelRagChunks\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class InstallRagChunksCommand extends Command
{
    protected $signature = 'rag-chunks:install';

    protected $description = 'Install the Laravel Rag Chunks package';

    public function handle(): void
    {
        $this->info('Installing Laravel Rag Chunks...');

        // 1. Publish Config Manually to ensure reliability
        $configFile = config_path('rag_chunks.php');
        if (! file_exists($configFile)) {
            $sourceConfig = __DIR__ . '/../../../config/rag_chunks.php';
            if (file_exists($sourceConfig)) {
                copy($sourceConfig, $configFile);
                $this->info('Published config/rag_chunks.php');
            } else {
                $this->warn('Could not find source config file to publish.');
            }
        } else {
            $this->warn('Config file already exists.');
        }

        // 2. Ask for Driver
        $driver = $this->choice(
            'Which database driver are you using?',
            ['postgres', 'other'],
            'postgres'
        );

        // 3. Ask for Embedder
        $embedder = $this->choice(
            'Which embedding driver do you want to use?',
            ['openai'],
            'openai'
        );

        // 4. Publish Migrations
        $this->publishMigrations($driver);

        // 5. Update Configuration
        $this->updateConfiguration($driver, $embedder);

        $this->info('Laravel Rag Chunks installed successfully.');
        $this->info('Please review config/rag_chunks.php and .env settings.');
    }

    protected function publishMigrations(string $driver): void
    {
        $filesystem = new Filesystem;

        $migrations = [
            'create_tags_blueprints_table.php.stub' => 'create_tags_blueprints_table',
            'create_projects_table.php.stub' => 'create_projects_table',
            'create_documents_table.php.stub' => 'create_documents_table',
            'create_document_project_shares_table.php.stub' => 'create_document_project_shares_table',
            'create_chunks_table.php.stub' => 'create_chunks_table',
        ];

        $stubPath = __DIR__.'/../../../stubs/migrations';

        $baseTime = time();
        $count = 0;

        foreach ($migrations as $stub => $name) {
            $timestamp = date('Y_m_d_His', $baseTime + $count);

            // Look in driver specific folder first, then generic
            $source = "$stubPath/$driver/$stub";
            if (! file_exists($source)) {
                $source = "$stubPath/generic/$stub";
            }

            if (! file_exists($source)) {
                $this->warn("Stub $stub not found in $driver or generic.");

                continue;
            }

            // Check if migration already exists (glob pattern match)
            if (! $this->migrationExists($name)) {
                $target = database_path("migrations/{$timestamp}_{$name}.php");
                $filesystem->copy($source, $target);
                $this->info("Published migration: $name");
                $count++;
            } else {
                $this->info("Migration $name already exists.");
            }
        }
    }

    protected function migrationExists(string $name): bool
    {
        $files = glob(database_path('migrations/*_'.$name.'.php'));

        return count($files) > 0;
    }

    protected function updateConfiguration(string $driver, string $embedder): void
    {
        $configFile = config_path('rag_chunks.php');

        if (! file_exists($configFile)) {
            return;
        }

        $content = file_get_contents($configFile);

        // Update Driver
        // Assuming config has 'driver' => ChunkModel::POSTGRES, or similar
        // We replace the line 'driver' => ... with the new value
        $driverUpper = strtoupper($driver);
        $content = preg_replace(
            "/'driver' => .*,/",
            "'driver' => \SimoneBianco\LaravelRagChunks\Enums\ChunkModel::{$driverUpper},",
            $content
        );

        // Update Embedder
        $embedderUpper = strtoupper($embedder);
        $content = preg_replace(
            "/'embedding' => .*,/",
            "'embedding' => \SimoneBianco\LaravelRagChunks\Enums\EmbeddingDriver::{$embedderUpper},",
            $content
        );

        // Also update embedders array key if needed, or specific config?
        // User asked to set the selected embedder. The config structure keys might already be fine.
        // The default config structure:
        // 'embedders' => [ EmbeddingDriver::OPENAI->value => ... ]
        // We don't need to change the array keys, just the default 'embedding' selection.

        file_put_contents($configFile, $content);
        $this->info("Updated config/rag_chunks.php with driver: $driver and embedder: $embedder");
    }
}
