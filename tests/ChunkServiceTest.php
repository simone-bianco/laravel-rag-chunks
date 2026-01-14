<?php

namespace SimoneBianco\LaravelRagChunks\Tests;

use Mockery;
use SimoneBianco\LaravelRagChunks\DTOs\DocumentDTO;
use SimoneBianco\LaravelRagChunks\Facades\HashService;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Services\Embedding\Contracts\EmbeddingDriverInterface;
use SimoneBianco\LaravelRagChunks\Services\RagService;

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
        $service = new RagService($embeddingMock, splitSize: 100);

        // 3. Execute
        $text = "This is a test text that should be chunked.";
        $alias = 'test_alias_unique';
        $document = $service->createChunks(new DocumentDTO(text: $text, alias: $alias));
        $chunks = $document->chunks;

        // 4. Assertions
        $this->assertCount(1, $chunks);
        $this->assertInstanceOf(Chunk::class, $chunks->first());
        $this->assertEquals([0.1, 0.2, 0.3], $chunks->first()->embedding);

        $this->assertDatabaseHas('documents', [
            'alias' => $alias,
            'hash' => HashService::hash($text)
        ]);

        $this->assertDatabaseCount('chunks', 1); // Document has 1 chunk
    }

    public function test_it_replaces_existing_chunks()
    {
        $embeddingMock = Mockery::mock(EmbeddingDriverInterface::class);
        $embeddingMock->shouldReceive('embed')->times(2)->andReturn([0.1]);

        $service = new RagService($embeddingMock, splitSize: 10); // Small size -> multiple chunks

        $text = "1234567890EXTRA";
        $dto = new DocumentDTO(text: $text, alias: 'test_alias');

        // Initial create
        $service->createChunks($dto);
        $this->assertDatabaseCount('chunks', 2);

        // Update (uses same alias/hash inside DTO logic)
        $service->createChunks($dto);
        // Should still be 2, old ones deleted
        $this->assertDatabaseCount('chunks', 2);
    }
}
