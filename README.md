# Laravel RAG Chunks

A robust Laravel package for chunking text documents and managing vector embeddings, designed for RAG (Retrieval-Augmented Generation) applications.

## Features

- **Smart Chunking**: Splits large texts into semantic chunks with hashing and deduplication.
- **Vector Embeddings**: Integrated support for OpenAI embeddings (expandable to other drivers).
- **Dynamic Storage**: Support for **PostgreSQL (pgvector)** and generic JSON fallbacks.
- **Atomic Updates**: Ensures data integrity when re-chunking documents.

## Installation

1. Install via Composer:

```bash
composer require simone-bianco/laravel-rag-chunks
```

2. Run the installation command:

```bash
php artisan rag-chunks:install
```

This interactive command will:

- Publish the configuration file.
- Ask which database driver you are using (`postgres` or `other`).
- Publish the appropriate migration file for your chosen driver.

3. Run migrations:

```bash
php artisan migrate
```

## Configuration

Edit `config/laravel-rag-chunks.php` to set your preferences. Ensure you have your OpenAI key set in your `.env`:

```env
OPENAI_API_KEY=sk-...
```

## Usage

### Chunking a Text

Use the `ChunkService` to split a text into chunks and generate embeddings automatically.

```php
use SimoneBianco\LaravelRagChunks\Chunk\ChunkService;

// Inject the service
public function index(ChunkService $chunkService)
{
    $text = "Long text content from a PDF or Markdown file...";

    // Create chunks and associate them with a new or existing Document
    $chunks = $chunkService->createChunks($text);

    // Access the created Document
    $document = $chunks->first()->document;

    return $document;
}
```

### Searching (Postgres Only)

If using the PostgreSQL driver, you can perform vector similarity searches:

```php
use SimoneBianco\LaravelRagChunks\Models\Chunk;

$queryVector = [...]; // Embedding of your search query

$nearestChunks = Chunk::query()
    ->nearestNeighbors('embedding', $queryVector, distance: 'cosine')
    ->limit(5)
    ->get();
```

## Testing

```bash
composer test
```
