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

    /** Where the browser should fetch engine-served assets from. */
    public static function baseUrl(): string
    {
        return self::base();
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
                                  int $topK, int $candidates, float $minScore,
                                  ?float $rerankMinScore = null,
                                  ?float $minSimilarity = null,
                                  ?float $keywordWeight = null,
                                  ?int $neighbours = null): array
    {
        try {
            $body = [
                'collection_ids' => array_values($collectionIds),
                'query' => $query,
                'mode' => $mode,
                'top_k' => $topK,
                'candidates' => $candidates,
                'min_score' => $minScore,
            ];

            // Only sent when asked for, so an engine older than the reranker
            // is not handed a key it would refuse.
            if ($rerankMinScore !== null) {
                $body['rerank_min_score'] = $rerankMinScore;
            }
            if ($minSimilarity !== null) {
                $body['min_similarity'] = $minSimilarity;
            }
            if ($keywordWeight !== null) {
                $body['keyword_weight'] = $keywordWeight;
            }
            if ($neighbours !== null) {
                $body['neighbours'] = $neighbours;
            }

            $response = self::request()->post(self::base() . '/api/v1/kb/search', $body);

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

    /**
     * One real search with a web search key. The engine reports success with
     * the number of results, or the provider's complaint, so a typo is found
     * before a visitor finds it.
     */
    public static function testWebSearch(string $provider, string $apiKey): array
    {
        try {
            $response = self::request()->post(self::base() . '/api/v1/kb/websearch/test', [
                'provider' => $provider,
                'api_key' => $apiKey,
            ]);

            if (!$response->successful()) {
                return ['success' => false, 'message' => 'Engine returned HTTP ' . $response->status()];
            }

            $body = $response->json();

            return ['success' => (bool) ($body['ok'] ?? false), 'message' => (string) ($body['message'] ?? '')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not reach the engine: ' . $e->getMessage()];
        }
    }

    /** A short sample in one of the four voices, from the saved settings. */
    public static function testVoice(string $voice, string $text = '', string $voiceName = '', array $unsaved = []): array
    {
        try {
            $response = self::request()->post(self::base() . '/api/v1/voice/test',
                array_filter(['voice' => $voice, 'text' => $text, 'voice_name' => $voiceName] + $unsaved,
                    fn ($v) => $v !== ''));

            if (!$response->successful()) {
                return ['ok' => false, 'message' => $response->body() ?: 'Engine returned HTTP ' . $response->status()];
            }

            return ['ok' => true, 'audio' => $response->body(),
                'type' => $response->header('Content-Type') ?: 'audio/mpeg'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach the engine: ' . $e->getMessage()];
        }
    }

    /**
     * The voices a speech server names, for the Voice page's lists. A server
     * that names none (OpenAI, openai-edge-tts) gives an empty list.
     */
    public static function speechVoices(string $baseUrl, string $apiKey): array
    {
        try {
            $response = self::request()->post(self::base() . '/api/v1/voice/server-voices', [
                'base_url' => $baseUrl,
                'api_key' => $apiKey,
            ]);

            return $response->successful()
                ? $response->json()
                : ['ok' => false, 'voices' => [], 'message' => 'Engine returned HTTP ' . $response->status()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'voices' => [], 'message' => 'Could not reach the engine: ' . $e->getMessage()];
        }
    }

    public static function listModels(string $baseUrl, string $apiKey): array
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

    /**
     * The chat models an endpoint offers, for the bot form's model picker.
     * The key is sent from here, never from the browser.
     */
    public static function chatModels(string $baseUrl, string $apiKey): array
    {
        try {
            $response = self::request()->timeout(30)->post(self::base() . '/api/v1/bot/fetch-models', [
                'base_url' => $baseUrl,
                'api_key' => $apiKey,
            ]);

            return $response->successful()
                ? $response->json()
                : ['success' => false, 'models' => [], 'message' => 'Engine returned HTTP ' . $response->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'models' => [], 'message' => 'Could not reach the engine: ' . $e->getMessage()];
        }
    }

    /**
     * Asks the model for a word, for the bot form's Test inference. The engine
     * waits up to a minute for a cold model, so this waits a little longer.
     */
    public static function testInference(string $baseUrl, string $apiKey, string $model): array
    {
        try {
            $response = self::request()->timeout(75)->post(self::base() . '/api/v1/bot/test-connection', [
                'base_url' => $baseUrl,
                'api_key' => $apiKey,
                'model_name' => $model,
            ]);

            return $response->successful()
                ? $response->json()
                : ['success' => false, 'message' => 'Engine returned HTTP ' . $response->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not reach the engine: ' . $e->getMessage()];
        }
    }
}
