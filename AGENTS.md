# laravel-rag-chunks — RAG Core Engine

## OVERVIEW
Core RAG package: document parsing, semantic chunking, vector embeddings (pgvector), AI agents (LarAgent), and vector similarity search. Provides Project, Document, Chunk, Tag, Embedding models used throughout the main app.

## STRUCTURE
```
src/
├── Models/              # Eloquent: Project, Document, Chunk, Tag, Embedding, Relation, Feedback, ProjectGroup
├── Services/
│   ├── ChunkService     # Core chunking logic
│   ├── DocumentService  # Document lifecycle
│   ├── FileService      # File handling (ALWAYS use this, NOT Storage::)
│   ├── ProjectService   # Project operations
│   ├── TagService       # Tag management
│   ├── RagService       # RAG orchestration
│   ├── ProcessService   # Process tracking
│   ├── HashService      # Content hashing
│   ├── StreamService    # Streaming operations
│   ├── Parsers/         # PDF, Word, Markdown, JSON, JSONL, TXT
│   ├── Chunkers/        # Semantic and markdown chunking strategies
│   └── Converters/      # Document format conversion
├── AiAgents/            # LarAgent-based AI agents
│   ├── MemoryMergeAgent.php   # Merges similar search memory queries/notes via AI (LarAgent) with deterministic fallback
│   ├── ProjectSearchAgent    # Vector search with context
│   ├── ProjectChatAgent      # Chat with RAG context
│   ├── RagSearchAgent        # Basic RAG search
│   ├── TagsCreatorAgent      # Auto-generate tags
│   ├── ImageDescriptorAgent  # Describe images via AI
│   └── Tools/               # SearchChunks, SearchInProject, GetNextChunk, ConnectChunks
├── Drivers/Embedding/
│   ├── EmbeddingDriverInterface  # Contract
│   ├── OpenaiEmbeddingDriver     # OpenAI embeddings
│   └── MultiembedderDriver       # Multi-provider
└── LaravelRagChunksServiceProvider.php
```

## RAG PIPELINE
1. **Upload** → `FileService` stores file
2. **Parse** → `Parsers/` converts to raw text (PDF, Word, Markdown, JSON, JSONL, TXT)
3. **Chunk** → `Chunkers/` splits into semantic units
4. **Embed** → `EmbeddingDriver` generates vectors via OpenAI
5. **Store** → PostgreSQL with pgvector extension
6. **Search** → Vector similarity via `SearchChunks` tool
7. **Agent** → `ProjectSearchAgent` / `ProjectChatAgent` orchestrates retrieval + generation

## CONVENTIONS
- Models in THIS package, NOT `app/Models/` — Project, Document, Chunk, Tag, Embedding
- File ops: ALWAYS use `FileService`, never raw `Storage::`
- AI agents extend `LarAgent\Agent` with tools for chunk search/navigation
- Config in `config/rag_chunks.php` — embedding driver, semantic weights, retry logic
