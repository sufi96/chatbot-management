<?php

namespace App\Services\Schema;

/**
 * The same read-only rules the engine applies, applied again here.
 *
 * Deliberate duplication. The engine is a caller like any other and its
 * validation is not evidence, so nothing reaches a customer's database on the
 * strength of a check that happened in another process.
 */
final class SqlGuard
{
    private const IDENTIFIER = '(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|[A-Za-z_][\w$]*(?:\.[A-Za-z_][\w$]*)*)';

    /** Matched as whole words. A column named created_at is not a CREATE. */
    private const FORBIDDEN = [
        'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate',
        'grant', 'revoke', 'merge', 'exec', 'execute', 'call', 'into',
        'attach', 'pragma', 'copy',
    ];

    /**
     * @param  list<string>  $allowed  lowercase qualified table names
     * @return string|null a complaint, or null when the statement is acceptable
     */
    public static function check(string $sql, array $allowed): ?string
    {
        $cleaned = trim(preg_replace('/;\s*$/', '', self::stripComments($sql)));

        if ($cleaned === '') {
            return 'The statement was empty.';
        }

        if (str_contains($cleaned, ';')) {
            return 'Only one statement may be sent.';
        }

        if (!preg_match('/^(select|with)\b/i', $cleaned)) {
            return 'The statement must begin with SELECT or WITH.';
        }

        foreach (self::FORBIDDEN as $verb) {
            if (preg_match('/\b' . $verb . '\b/i', $cleaned)) {
                return strtoupper($verb) . ' is not allowed. Only reading is permitted.';
            }
        }

        $permitted = array_map('strtolower', $allowed);
        foreach (self::cteNames($cleaned) as $name) {
            $permitted[] = $name;
        }

        foreach (self::referencedTables($cleaned) as $table) {
            if (!in_array($table, $permitted, true)) {
                return "The table {$table} is not one this bot may read.";
            }
        }

        return null;
    }

    /** Comments out, string literals preserved. */
    public static function stripComments(string $sql): string
    {
        $literals = [];
        $masked = preg_replace_callback("/'(?:[^']|'')*'/", function ($match) use (&$literals) {
            $literals[] = $match[0];

            return "\x00" . (count($literals) - 1) . "\x00";
        }, $sql);

        $masked = preg_replace('/\/\*.*?\*\//s', ' ', $masked);
        $masked = preg_replace('/--[^\n]*/', ' ', $masked);

        return preg_replace_callback("/\x00(\d+)\x00/", fn ($m) => $literals[(int) $m[1]], $masked);
    }

    /** @return list<string> */
    public static function referencedTables(string $sql): array
    {
        preg_match_all('/\b(?:from|join)\s+(' . self::IDENTIFIER . ')/i',
            self::stripComments($sql), $matches);

        return array_values(array_unique(array_map(
            fn ($name) => self::unquote($name), $matches[1])));
    }

    /** @return list<string> */
    private static function cteNames(string $sql): array
    {
        preg_match_all('/\b(?:with|,)\s+(' . self::IDENTIFIER . ')\s+as\s*\(/i', $sql, $matches);

        return array_map(fn ($name) => self::unquote($name), $matches[1]);
    }

    private static function unquote(string $identifier): string
    {
        return strtolower(trim(trim(trim(trim($identifier), '"'), '`'), '[]'));
    }
}
