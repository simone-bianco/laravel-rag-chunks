<?php

namespace SimoneBianco\LaravelRagChunks\Console\Commands;

use Illuminate\Console\Command;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class TestDispatchParsingCommand extends Command
{
    protected $signature = 'rag-chunks:test-dispatch 
                            {file? : Absolute path to the PDF file (optional, uses bundled test.pdf if not provided)}';

    protected $description = 'Test the PdfParser::dispatchParsing service directly';

    public function __construct(protected PdfParser $pdfParser)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $filePath = $this->argument('file') ?? __DIR__ . '/test.pdf';

        if (!file_exists($filePath)) {
            $this->error("File not found: $filePath");
            return self::FAILURE;
        }

        $this->info("Testing PdfParser::dispatchParsing()");
        $this->info("File: $filePath");
        $this->newLine();

        try {
            $this->info('Calling dispatchParsing...');
            $result = $this->pdfParser->dispatchParsing($filePath);

            $this->info('SUCCESS!');
            $this->newLine();
            $this->table(
                ['Key', 'Value'],
                collect($result)->map(fn($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])->toArray()
            );

            $this->newLine();
            $this->info("Use this job_id to test polling:");
            $this->line("  php artisan rag-chunks:test-poll {$result['job_id']}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('FAILED!');
            $this->error("Exception: " . get_class($e));
            $this->error("Message: " . $e->getMessage());

            if (method_exists($e, 'getResponse')) {
                $this->newLine();
                $this->warn('Response data:');
                $this->line(json_encode($e->getResponse(), JSON_PRETTY_PRINT));
            }

            return self::FAILURE;
        }
    }
}
