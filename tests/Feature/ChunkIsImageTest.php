<?php

namespace SimoneBianco\LaravelRagChunks\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Models\Project;
use SimoneBianco\LaravelRagChunks\Tests\TestCase;

class ChunkIsImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_create_chunk_with_is_image_flag()
    {
        $project = Project::create([
            'name' => 'Test Project',
            'hash' => 'proj123',
        ]);

        $document = Document::create([
            'project_id' => $project->id,
            'name' => 'Test Doc',
            'hash' => 'hash123',
            'content' => 'content',
            'alias' => 'test-doc', // Required by unique constraint
        ]);

        $chunk = Chunk::create([
            'document_id' => $document->id,
            'content' => 'Image content',
            'hash' => 'chunkhash123',
            'page' => 1,
            'is_image' => true,
        ]);

        $this->assertTrue($chunk->is_image);
        $this->assertDatabaseHas('chunks', [
            'id' => $chunk->id,
            'is_image' => true,
        ]);
    }

    public function test_is_image_defaults_to_false()
    {
        $project = Project::create([
            'name' => 'Test Project 2',
            'hash' => 'proj456',
        ]);

        $document = Document::create([
            'project_id' => $project->id,
            'name' => 'Test Doc 2',
            'hash' => 'hash456',
            'content' => 'content',
            'alias' => 'test-doc-2',
        ]);

        $chunk = Chunk::create([
            'document_id' => $document->id,
            'content' => 'Text content',
            'hash' => 'chunkhash456',
            'page' => 1,
        ]);

        $this->assertFalse($chunk->is_image);
        $this->assertDatabaseHas('chunks', [
            'id' => $chunk->id,
            'is_image' => false,
        ]);
    }
}
