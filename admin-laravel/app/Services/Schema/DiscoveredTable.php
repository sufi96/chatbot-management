<?php

namespace App\Services\Schema;

final class DiscoveredTable
{
    /** @param list<DiscoveredColumn> $columns */
    public function __construct(
        public readonly ?string $schema,
        public readonly string $name,
        public readonly array $columns,
    ) {
    }

    /** The key a discovery run and a stored row are matched on. */
    public function key(): string
    {
        return ($this->schema ?? '') . '|' . $this->name;
    }
}
