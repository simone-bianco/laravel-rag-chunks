<?php

namespace SimoneBianco\LaravelRagChunks\Tests;

use Illuminate\Support\Facades\Hash;
use Mockery;
use SimoneBianco\LaravelRagChunks\Exceptions\ChunkingFailedException;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\Chunk\ChunkService;
use SimoneBianco\LaravelRagChunks\Services\Embedding\Contracts\EmbeddingDriverInterface;

class ChunkServiceTest extends TestCase
{
    public function test_it_chunks_text_and_saves_to_database()
    {
        // 1. Mock Embedding Driver
        $embeddingMock = Mockery::mock(EmbeddingDriverInterface::class);
        $embeddingMock->shouldReceive('embed')
            ->once() // Only called once because text is short enough for 1 chunk
            ->andReturn([0.1, 0.2, 0.3]);

        // 2. Instantiate Service
        $service = new ChunkService($embeddingMock, splitSize: 100);

        // 3. Execute
        $text = "This is a test text that should be chunked.";
        $chunks = $service->createChunks($text);

        // 4. Assertions
        $this->assertCount(1, $chunks);
        $this->assertInstanceOf(Chunk::class, $chunks->first());
        $this->assertEquals([0.1, 0.2, 0.3], $chunks->first()->embedding);
        
        $this->assertDatabaseHas('documents', [
            'hash' => hash('sha256', $text) // Note: This might fail if Hash::make is random. Document hash checking logic might need revision or loose check.
        ]);
        
        $this->assertDatabaseCount('chunks', 1);
    }

    public function test_it_replaces_existing_chunks()
    {
        $embeddingMock = Mockery::mock(EmbeddingDriverInterface::class);
        $embeddingMock->shouldReceive('embed')->times(2)->andReturn([0.1]);

        $service = new ChunkService($embeddingMock, splitSize: 10); // Small size -> multiple chunks

        $text = "1234567890EXTRA";
        $document = Document::create(['hash' => 'old_hash', 'alias' => 'test']);
        
        // Initial create
        $service->createChunks($text, $document);
        $this->assertDatabaseCount('chunks', 2);

        // Update (clean slate logic)
        $service->createChunks($text, $document);
        // Should still be 2, old ones deleted
        $this->assertDatabaseCount('chunks', 2);
    }
}
