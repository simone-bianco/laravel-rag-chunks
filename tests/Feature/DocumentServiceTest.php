<?php

namespace SimoneBianco\LaravelRagChunks\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use SimoneBianco\LaravelRagChunks\Services\FileService;
use SimoneBianco\LaravelRagChunks\Services\DocumentService;
use SimoneBianco\LaravelRagChunks\Services\StreamService;
use SimoneBianco\LaravelRagChunks\Tests\TestCase;
use Illuminate\Contracts\Filesystem\FileNotFoundException;

class DocumentServiceTest extends TestCase
{
    public function test_calculate_file_hash_delegates_to_hash_service()
    {
        $path = '/path/to/file.txt';
        $pExpectedHash = 'hashed_value';

        \SimoneBianco\LaravelRagChunks\Facades\HashService::shouldReceive('hashFile')
            ->once()
            ->with($path)
            ->andReturn($pExpectedHash);

        $streamServiceMock = $this->createMock(StreamService::class);
        $fileServiceMock = $this->createMock(FileService::class);
        $service = new DocumentService($streamServiceMock, $fileServiceMock);
        
        $hash = $service->calculateFileHash($path);
        
        $this->assertEquals($pExpectedHash, $hash);
    }

    public function test_calculate_file_hash_converts_runtime_exception_to_file_not_found_exception()
    {
        $this->expectException(FileNotFoundException::class);
        $path = 'non_existent.txt';

        \SimoneBianco\LaravelRagChunks\Facades\HashService::shouldReceive('hashFile')
            ->once()
            ->with($path)
            ->andThrow(new \RuntimeException("File not found"));

        $streamServiceMock = $this->createMock(StreamService::class);
        $fileServiceMock = $this->createMock(FileService::class);
        $service = new DocumentService($streamServiceMock, $fileServiceMock);
        
        $service->calculateFileHash($path);
    }
}
