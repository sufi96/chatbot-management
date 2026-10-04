<?php

namespace App\Support;

/**
 * Sorting a provider's model list by job, from the names alone.
 *
 * One endpoint often publishes models for several jobs: OpenAI lists
 * gpt-4o beside text-embedding-3-small, tts-1 and whisper-1. A picker
 * for one job shows that job's models. The patterns are the well-known
 * name parts; a name they miss is still shown somewhere, and any name can
 * still be typed by hand.
 *
 * - For embedding, rerank, speech or transcription: the names that match
 *   the job, or every name when none do (a server with unusual names).
 * - For chat: every name that does not plainly belong to another job.
 */
class ModelPurpose
{
    private const PATTERNS = [
        'embedding' => '/embed|(^|[\/:_-])(bge-(m3|base|large|small)|e5-|gte-|minilm|mxbai)/i',
        'rerank' => '/rerank/i',
        'speech' => '/(^|[\/:._-])tts|text-to-speech|kokoro|piper|xtts|orpheus|parler|(^|[\/:_-])(f5|vits)([\/:._-]|$)/i',
        'transcription' => '/whisper|transcri|(^|[\/:_-])(stt|asr)([\/:._-]|$)|parakeet|sensevoice/i',
        // Not a picker of their own, but never a chat model either.
        'image' => '/dall-?e|gpt-image|moderation|stable-diffusion|sdxl/i',
    ];

    /**
     * The models for a purpose, and how many were left out for other jobs.
     *
     * @param  array<int, string>  $models
     * @return array{models: array<int, string>, hidden: int}
     */
    public static function filter(array $models, string $purpose): array
    {
        $models = array_values(array_filter($models, 'is_string'));

        if ($purpose === 'chat') {
            $kept = array_values(array_filter($models, fn (string $name) => self::kindOf($name) === null));
        } elseif (isset(self::PATTERNS[$purpose])) {
            $kept = array_values(array_filter($models, fn (string $name) => (bool) preg_match(self::PATTERNS[$purpose], $name)));
        } else {
            $kept = $models;
        }

        // Nothing recognised: show everything rather than nothing.
        if (!$kept) {
            return ['models' => $models, 'hidden' => 0];
        }

        return ['models' => $kept, 'hidden' => count($models) - count($kept)];
    }

    /** The job a name plainly belongs to besides chat, or null. Rerank before embedding: "bge-reranker" is a reranker. */
    public static function kindOf(string $name): ?string
    {
        foreach (['rerank', 'embedding', 'speech', 'transcription', 'image'] as $kind) {
            if (preg_match(self::PATTERNS[$kind], $name)) {
                return $kind;
            }
        }

        return null;
    }
}
