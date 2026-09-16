<?php

namespace App\Services\Schema;

use App\Models\DbConnection;

/**
 * An unsaved connection built from form fields, so a person can test before
 * they commit. Nothing here touches the database.
 *
 * The password rule is the only subtlety. The edit form never renders a
 * stored password, so a blank field means "the one already saved" rather than
 * "no password", and without that fallback Test would fail on every edit and
 * tell the operator nothing.
 */
final class DraftConnection
{
    /** @param array<string,mixed> $fields */
    public static function build(array $fields, ?DbConnection $existing = null): DbConnection
    {
        $password = $fields['password'] ?? '';
        if ($password === '' || $password === null) {
            $password = $existing?->password;
        }

        return new DbConnection([
            'id' => $existing?->id ?? 'dbc_draft',
            'system_id' => $existing?->system_id ?? '',
            'name' => $fields['name'] ?? 'Draft',
            'driver' => $fields['driver'],
            'host' => $fields['host'] ?? null,
            'port' => $fields['port'] ?? null,
            'database' => $fields['database'],
            'username' => $fields['username'] ?? null,
            'password' => $password,
            'options' => $fields['options'] ?? [],
        ]);
    }
}
