# What If: Architettura Alternativa Flessibile

**Attenzione: Questo documento è un'analisi teorica. Nessuna implementazione deve essere eseguita.**

## Scenario

Immaginiamo di voler mantenere (o ripristinare) la promessa originale: supportare molteplici backend vettoriali (Postgres/pgvector, Qdrant, Weaviate, Pinecone, Milvus) in modo trasparente.

## Architettura Necessaria

Per fare ciò, il package non potrebbe più basarsi su Eloquent e `pgvector` direttamente nel `ChunkService`. Servirebbe un layer di astrazione molto più spesso (Repository Pattern).

### Componenti Chiave

1.  **VectorStoreInterface**
    Definisce i metodi agnostici:

    ```php
    interface VectorStoreInterface {
        public function store(Document $doc, array $chunks): void;
        public function search(array $vector, float $minScore): Collection;
        public function delete(Document $doc): void;
    }
    ```

2.  **Driver Specifici (Adapters)**
    - `PostgresVectorStore` (implementa logica SQL/Eloquent)
    - `QdrantVectorStore` (chiamate API HTTP a Qdrant)
    - `WeaviateVectorStore` (chiamate API GraphQL/REST)

3.  **DTO Standardizzati**
    I risultati della ricerca non sarebbero più modelli `Chunk` Eloquent diretti, ma oggetti `ScoredChunkDTO` standardizzati, poiché Qdrant e Postgres ritornano dati in formati diversi.

### Pro e Contro

| Aspetto          | Architettura Postgres-Only (Piano A)                                                     | Architettura Flessibile (Piano B)                                                                                                                   |
| :--------------- | :--------------------------------------------------------------------------------------- | :-------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Complessità**  | Bassa. Usiamo Eloquent, relazioni, join SQL.                                             | Alta. Non possiamo fare JOIN tra metadati (MySQL) e vettori (Qdrant) facilmente.                                                                    |
| **Performance**  | Alta (DB unico, meno network roundtrips).                                                | Dipende. Spesso peggiore se si devono incrociare dati relazionali e vettoriali (il problema del "filter then search").                              |
| **Funzionalità** | Possiamo usare feature avanzate di Postgres (Hybrid Search con Full Text Search nativo). | "Minimo comun denominatore". Possiamo usare solo le feature supportate da TUTTI i driver, o riempire il codice di `if ($driver instanceof Qdrant)`. |
| **Deployment**   | Semplice (solo 1 container DB).                                                          | Complesso (serve gestire/deployare Qdrant/Weaviate a parte).                                                                                        |
| **Testing**      | Facile (refresh database trait).                                                         | Difficile (serve mockare API esterne o spin-up di container docker multipli).                                                                       |

## Il Problema del "Minimo Comun Denominatore"

Il punto critico è la **Hybrid Search** (Keyword + Semantic).

- In Postgres: Fai una query SQL con `ts_rank` + `<=>` cosine distance. Facile ed efficiente.
- In Qdrant: Devi usare le loro API specifiche per hybrid search (che funzionano diversamente).
- In architettura flessibile: Devi scrivere 2 implementazioni completamente diverse della logica di ricerca, raddoppiando i bug e il mantenimento.

## Conclusione dello Studio

Mantenere l'architettura flessibile ha senso solo se il business requirement prevede esplicitamente clienti che **non possono** usare Postgres (es. policy aziendali che impongono Qdrant/Elastic).
Se l'utente finale ha controllo sullo stack, l'approccio **Postgres-Only** vince per semplicità, potenza (JOIN relazionali) e mantenimento. L'astrazione attuale nel package è un "dead weight" che paga il costo della complessità senza offrire i benefici della flessibilità reale.
