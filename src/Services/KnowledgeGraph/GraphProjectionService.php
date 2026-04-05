<?php

namespace SimoneBianco\LaravelRagChunks\Services\KnowledgeGraph;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GraphProjectionService
{
    private const LOG_CHANNEL = 'knowledge-graph';

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

        Log::channel(self::LOG_CHANNEL)->info('projection.start', [
            'project_id' => $projectId,
            'url'        => $url,
        ]);

        $t = microtime(true);

        try {
            $response = Http::timeout(300)
                ->withToken(config('rag_chunks.knowledge_graph.projection_key'))
                ->post($url, ['project_id' => $projectId]);

            $elapsed = round((microtime(true) - $t) * 1000);

            if (! $response->ok()) {
                Log::channel(self::LOG_CHANNEL)->error('projection.http_error', [
                    'project_id'  => $projectId,
                    'status'      => $response->status(),
                    'elapsed_ms'  => $elapsed,
                    'body_snippet' => substr($response->body(), 0, 300),
                ]);
                $response->throw();
            }

            $json = $response->json();

            Log::channel(self::LOG_CHANNEL)->info('projection.success', [
                'project_id'    => $projectId,
                'elapsed_ms'    => $elapsed,
                'nodes'         => count($json['nodes'] ?? []),
                'document_nodes' => count($json['document_nodes'] ?? []),
                'edges'         => count($json['edges'] ?? []),
                'clusters'      => count($json['clusters'] ?? []),
                'meta'          => $json['meta'] ?? [],
            ]);

            return $json;
        } catch (RequestException $e) {
            Log::channel(self::LOG_CHANNEL)->error('projection.request_exception', [
                'project_id'  => $projectId,
                'elapsed_ms'  => round((microtime(true) - $t) * 1000),
                'message'     => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
