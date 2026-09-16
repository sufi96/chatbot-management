<?php

namespace App\Support;

use App\Models\AppSetting;
use Throwable;

/**
 * Which mark this installation shows.
 *
 * One place, because the sidebar, both login screens and the browser tab all
 * have to agree, and each working out its own fallback is how they drift.
 */
class Brand
{
    /** The wide wordmark, for the sidebar and the login screens. */
    public static function logoUrl(): string
    {
        return self::resolve('brand_logo_path', 'brand/logo.png');
    }

    /** The square mark, for the browser tab and a home screen. */
    public static function iconUrl(): string
    {
        return self::resolve('brand_icon_path', 'brand/logo-square.png');
    }

    /** True when somebody uploaded their own, which is what the revert tick
     *  and the "currently the built-in one" note both key off. */
    public static function isCustom(string $key): bool
    {
        return self::stored($key) !== '';
    }

    private static function resolve(string $key, string $builtIn): string
    {
        $stored = self::stored($key);

        return $stored === '' ? asset($builtIn) : asset('storage/' . $stored);
    }

    /**
     * The stored path, or nothing at all if it cannot be read.
     *
     * Every page draws a mark, including the sign-in screen, which is the one
     * page somebody reaches when the rest of the system is unwell. A database
     * that is down, unmigrated or mid-install must leave them looking at the
     * built-in logo, not at a stack trace.
     */
    private static function stored(string $key): string
    {
        try {
            return trim((string) AppSetting::get($key));
        } catch (Throwable $unreachable) {
            return '';
        }
    }
}
