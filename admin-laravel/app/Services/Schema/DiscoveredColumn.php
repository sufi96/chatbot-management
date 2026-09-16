<?php

namespace App\Services\Schema;

/**
 * One column as the database describes it. Carries no annotation: a
 * description belongs to the stored row, never to a discovery run, which is
 * what stops introspection overwriting what a person wrote.
 */
final class DiscoveredColumn
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $dataType,
        public readonly bool $isNullable,
        public readonly bool $isPrimaryKey,
        public readonly ?string $foreignKeyTarget,
        public readonly int $ordinal,
    ) {
    }
}
