# Studio Refactoring: Consolidamento PostgreSQL

**Attenzione: Questo documento è solo uno studio. Nessuna implementazione deve essere eseguita.**

## Obiettivo

Rimuovere tutti gli strati di astrazione (Driver, Factory, Interface) creati per supportare ipotetici database multipli, consolidando il codice su un'architettura puramente PostgreSQL (`pgvector`). Questo semplificherà drasticamente la codebase, rendendola più leggibile e manutenibile.

---

## Analisi Attuale

Il package `laravel-rag-chunks` attualmente:

1. Usa `ChunkingDriver::POSTGRES` nella configurazione.
2. Usa `tpetry/laravel-postgresql-enhanced` per il casting dei vettori (`VectorArray`).
3. Usa sintassi SQL nativa di Postgres (`<=>`, `<->`) nel `ChunkBuilder` e `HasNearestNeighbors`.
4. Mantiene tuttavia una struttura "a Driver" (`Drivers/Embedding`, Enums, Contracts) che suggerisce una flessibilità che di fatto non esiste (e non è utilizzata).

## Piano di Refactoring (da NON eseguire)

### 1. Eliminazione delle Interfacce Generiche

Rimuovere l'idea che ci possano essere diversi "storage driver". PostgreSQL è l'unico storage.

**File da Eliminare:**

- ❌ `src/Drivers/Embedding/Contracts/EmbeddingDriverInterface.php`
- ❌ `src/Enums/ChunkingDriver.php` (è superfluo, esiste solo Postgres)
- ❌ `src/Enums/EmbeddingDriver.php` (se usato solo per distinguere storage, altrimenti mantenere se serve per distinguere API provider come OpenAI vs Azure)

### 2. Semplificazione del Servizio di Embedding

Attualmente `EmbeddingFactory` decide quale driver usare. Se supportiamo solo OpenAI (o altri provider ma sempre salvando su Postgres), possiamo semplificare.

**Modifiche:**

- Rinominare `Drivers/Embedding` in `Services/Embedders`.
- Iniettare direttamente il servizio di embedding dove serve, senza passare da Factory complesse se non strettamente necessario per switching a runtime.

### 3. Hardcoding delle Funzionalità Postgres

Invece di affidarsi a configurazioni che potrebbero suggerire altro, rendere esplicito l'uso di Postgres.

**Snippets di Codice:**

In `src/Models/Chunk.php`, rimuovere il controllo configurazione per il cast:

```php
// PRIMA
protected function casts() {
    $embeddingCast = config('rag_chunks.embedding_cast', VectorArray::class);
    return ['embedding' => $embeddingCast];
}

// DOPO: Uso esplicito di VectorArray
use Tpetry\PostgresqlEnhanced\Eloquent\Casts\VectorArray;

protected function casts() {
    return [
        'embedding' => VectorArray::class,
        'semantic_tags_embedding' => VectorArray::class,
    ];
}
```

### 4. Pulizia del ChunkService

Il `ChunkService` può assumere che sta lavorando con modelli Eloquent che usano `HasNearestNeighbors` ottimizzato per Postgres.

Rimuovere logiche difensive come:

```php
$this->chunkModel ??= Arr::get($config, 'model.chunk', Chunk::class);
```

Se il package è "Opinionated Postgres RAG", allora usa il Modello Chunk fornito o esteso, ma sempre su Postgres.

### 5. Ottimizzazioni SQL Native

Poiché siamo "Postgres Only", possiamo sfruttare indici e operatori specifici senza timore.

- Assicurarsi che le migration creino indici HNSW:
  ```php
  $table->vector('embedding', 1536)->always(); // tpetry helper
  // Oppure raw SQL per indici specifici
  DB::statement("CREATE INDEX ON chunks USING hnsw (embedding vector_cosine_ops)");
  ```

---

## Risultato Atteso

Una codebase più snella dove:

- Non ci sono cartelle `Drivers/` vuote o con un solo file.
- Non ci sono `Interface` che hanno una sola implementazione.
- Il codice è onesto: "Faccio questo su Postgres", invece di "Potrei fare questo su qualsiasi cosa (ma in realtà solo Postgres)".
- Minore overhead cognitivo per chi legge il codice.
