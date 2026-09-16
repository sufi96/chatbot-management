<?php

namespace App\Support;

/**
 * The order a bot consults its sources in.
 *
 * Stored as one comma separated string because both this codebase and the
 * engine read it and neither needs to query into it. Never trusted to be well
 * formed: a hand written value, an older version's value and a mistyped one
 * all normalise rather than fail, so a bot can never end up with a source it
 * can never reach.
 */
class SourceOrder
{
    public const SOURCES = ['documents', 'database', 'web'];

    public const DEFAULT = 'documents,database,web';

    public static function normalise(?string $raw): string
    {
        $order = [];

        foreach (explode(',', (string) $raw) as $token) {
            $token = strtolower(trim($token));
            if (in_array($token, self::SOURCES, true) && !in_array($token, $order, true)) {
                $order[] = $token;
            }
        }

        // A token nobody listed is consulted last rather than not at all.
        foreach (self::SOURCES as $token) {
            if (!in_array($token, $order, true)) {
                $order[] = $token;
            }
        }

        return implode(',', $order);
    }

    /** The same order as a list, for a view that renders a row per source. */
    public static function toList(?string $raw): array
    {
        return explode(',', self::normalise($raw));
    }
}
