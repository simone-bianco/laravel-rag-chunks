<?php

namespace SimoneBianco\LaravelRagChunks\Console\Commands;

use Illuminate\Console\Command;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class TestPollParsingCommand extends Command
{
    protected $signature = 'rag-chunks:test-poll 
                            {job_id : The job_id returned from dispatchParsing}';

    protected $description = 'Test the PdfParser::pollParsing service directly';

    public function __construct(protected PdfParser $pdfParser)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $jobId = $this->argument('job_id');

        $this->info("Testing PdfParser::pollParsing()");
        $this->info("Job ID: $jobId");
        $this->newLine();

        try {
            $this->info('Calling pollParsing...');
            $status = $this->pdfParser->pollParsing(['job_id' => $jobId]);

            $this->info('SUCCESS!');
            $this->newLine();
            $this->info("Status: {$status->value}");

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
