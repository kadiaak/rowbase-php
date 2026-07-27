<?php

declare(strict_types=1);

namespace Rowbase\Schema;

/**
 * Une feuille et ses colonnes.
 */
final class SheetSchema
{
    /**
     * @param list<FieldSchema> $fields
     */
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly array $fields = [],
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $fields = [];
        foreach ((array) ($data['fields'] ?? []) as $field) {
            if (\is_array($field)) {
                $fields[] = FieldSchema::fromArray($field);
            }
        }

        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['slug'] ?? ''),
            (string) ($data['name'] ?? ''),
            $fields,
        );
    }

    public function field(string $nameOrId): ?FieldSchema
    {
        foreach ($this->fields as $field) {
            if ($field->name === $nameOrId || $field->id === $nameOrId) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function fieldNames(): array
    {
        return \array_map(static fn (FieldSchema $f): string => $f->name, $this->fields);
    }
}
