<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calls the FastAPI engine's admin plane.
 *
 * Every call carries the shared token; the engine rejects anything without it.
 * Failures are logged and reported as false rather than thrown, because a
 * queued re-index should not take an admin page down with it.
 */
class EngineClient
{
    private static function base(): string
    {
        return rtrim(env('ENGINE_BASE_URL', 'http://localhost:8000'), '/');
    }

    private static function request()
    {
        return Http::timeout(15)
            ->withHeaders(['X-Admin-Token' => env('ENGINE_ADMIN_TOKEN', '')]);
    }

    public static function indexSource(string $sourceId): bool
    {
        try {
            return self::request()
                ->post(self::base() . "/api/v1/kb/sources/{$sourceId}/index")
                ->successful();
        } catch (\Throwable $e) {
            Log::warning("Engine index call failed for {$sourceId}: " . $e->getMessage());

            return false;
        }
    }

    public static function deleteChunks(string $sourceId): bool
    {
        try {
            return self::request()
                ->delete(self::base() . "/api/v1/kb/sources/{$sourceId}/chunks")
                ->successful();
        } catch (\Throwable $e) {
            Log::warning("Engine delete call failed for {$sourceId}: " . $e->getMessage());

            return false;
        }
    }

    /**
     * Retrieval preview. Returns the decoded body, or an error entry the
     * playground can display: a tuning tool must never fail silently, because
     * "no results" and "the engine is down" look identical otherwise.
     */
    public static function search(array $collectionIds, string $query, string $mode,
                                  int $topK, int $candidates, float $minScore): array
    {
        try {
            $response = self::request()->post(self::base() . '/api/v1/kb/search', [
                'collection_ids' => array_values($collectionIds),
                'query' => $query,
                'mode' => $mode,
                'top_k' => $topK,
                'candidates' => $candidates,
                'min_score' => $minScore,
            ]);

            if (!$response->successful()) {
                return ['results' => [], 'error' => 'Engine returned HTTP ' . $response->status()];
            }

            return $response->json();
        } catch (\Throwable $e) {
            return ['results' => [], 'error' => 'Could not reach the engine: ' . $e->getMessage()];
        }
    }

    public static function reindexAll(int $dimensions): array
    {
        try {
            $response = self::request()->timeout(60)
                ->post(self::base() . '/api/v1/kb/reindex', ['dimensions' => $dimensions]);

            return $response->successful()
                ? $response->json()
                : ['status' => 'failed', 'message' => 'Engine returned HTTP ' . $response->status()];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'message' => 'Could not reach the engine: ' . $e->getMessage()];
        }
    }

    public static function testEmbedding(string $baseUrl, string $apiKey, string $model): array
    {
        try {
            $response = self::request()->post(self::base() . '/api/v1/kb/embedding/test', [
                'base_url' => $baseUrl,
                'api_key' => $apiKey,
                'model' => $model,
            ]);

            return $response->successful()
                ? $response->json()
                : ['ok' => false, 'message' => 'Engine returned HTTP ' . $response->status()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach the engine: ' . $e->getMessage()];
        }
    }

    public static function listEmbeddingModels(string $baseUrl, string $apiKey): array
    {
        try {
            $response = self::request()->post(self::base() . '/api/v1/kb/embedding/models', [
                'base_url' => $baseUrl,
                'api_key' => $apiKey,
            ]);

            return $response->successful()
                ? $response->json()
                : ['ok' => false, 'models' => [],
                   'message' => 'Engine returned HTTP ' . $response->status()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'models' => [],
                    'message' => 'Could not reach the engine: ' . $e->getMessage()];
        }
    }
}
