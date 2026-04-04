<?php

namespace SimoneBianco\LaravelRagChunks\Services\KnowledgeGraph;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class GraphProjectionService
{
    /**
     * Call the Python projection service to compute UMAP/clustering
     * for all embedded chunks in a project.
     *
     * PHP only sends the project_id; Python reads embeddings directly from Postgres.
     *
     * @return array{nodes: array, document_nodes: array, edges: array, clusters: array, meta: array}
     *
     * @throws RequestException
     */
    public function project(string $projectId): array
    {
        $url = rtrim(config('rag_chunks.knowledge_graph.projection_url'), '/') . '/graph/project-graph';
        $key = config('rag_chunks.knowledge_graph.projection_key');

        $response = Http::timeout(300)
            ->withToken($key)
            ->post($url, ['project_id' => $projectId]);

        $response->throw();

        return $response->json();
    }
}
