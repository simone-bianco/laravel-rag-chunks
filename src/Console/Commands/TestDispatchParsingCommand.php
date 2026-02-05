<?php

namespace SimoneBianco\LaravelRagChunks\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SimoneBianco\LaravelRagChunks\Jobs\Parsing\DispatchParsingJob;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Project;
use SimoneBianco\LaravelRagChunks\Services\DocumentService;
use SimoneBianco\LaravelRagChunks\Services\Parsers\PdfParser;
use Throwable;

class TestDispatchParsingCommand extends Command
{
    protected $signature = 'rag-chunks:test-dispatch';

    protected $description = 'Test the PdfParser::dispatchParsing service directly';

    public function __construct(protected PdfParser $pdfParser, protected DocumentService $documentService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info("Testing PdfParser::dispatchParsing()");

        try {
            $project = Project::firstOrFail();

            $document = $project->documents()->create([
                'alias' => Str::uuid()->toString(),
                'name' => 'test',
                'description' => 'PDF that explains how a keep was in medieval times',
                'extension' => 'pdf',
                'file_path' => '\test\test.pdf',
                'hash' => $this->documentService->calculateFileHash(Storage::disk('local')->path('\test\test.pdf'))
            ]);
            $document->save();

            $this->info('Calling dispatchParsing...');
//            $result = $this->pdfParser->dispatchParsing($absoluteFilePath);

            $result = DispatchParsingJob::dispatch($document->id);

            $this->info('SUCCESS!');

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
