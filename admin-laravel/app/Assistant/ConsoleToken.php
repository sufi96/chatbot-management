<?php

namespace App\Assistant;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Who is chatting with the console assistant.
 *
 * The console puts this on the widget when it renders a page; the widget
 * sends it with each message and each Confirm, and the engine hands it back
 * here untouched. Encrypted with the app key, so the engine can neither read
 * nor forge one: a leaked engine secret alone gives no admin's rights.
 */
class ConsoleToken
{
    public const HOURS = 12;

    public static function issue(User $user): string
    {
        return Crypt::encryptString(json_encode([
            'u' => $user->id,
            'exp' => now()->addHours(self::HOURS)->timestamp,
        ]));
    }

    public static function resolve(?string $token): ?User
    {
        if (!$token) {
            return null;
        }
        try {
            $claims = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }
        if (!is_array($claims) || ($claims['exp'] ?? 0) < now()->timestamp) {
            return null;
        }

        return User::find($claims['u'] ?? null);
    }
}
