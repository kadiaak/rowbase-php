<?php

declare(strict_types=1);

namespace Rowbase\Schema;

/**
 * Une colonne : { "id": "fld20", "name": "ID", "type": "text" }
 */
final class FieldSchema
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['type'] ?? ''),
        );
    }
}
