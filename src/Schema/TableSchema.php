<?php

declare(strict_types=1);

namespace Rowbase\Schema;

/**
 * Une table et ses feuilles, telles que renvoyées par GET /tables.
 */
final class TableSchema
{
    /**
     * @param list<SheetSchema> $sheets
     */
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly array $sheets = [],
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $sheets = [];
        foreach ((array) ($data['sheets'] ?? []) as $sheet) {
            if (\is_array($sheet)) {
                $sheets[] = SheetSchema::fromArray($sheet);
            }
        }

        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['slug'] ?? ''),
            (string) ($data['name'] ?? ''),
            $sheets,
        );
    }

    /**
     * Retrouve une feuille par slug, ID ou nom.
     */
    public function sheet(string $identifier): ?SheetSchema
    {
        foreach ($this->sheets as $sheet) {
            if ($sheet->slug === $identifier || $sheet->id === $identifier || $sheet->name === $identifier) {
                return $sheet;
            }
        }

        return null;
    }

    public function matches(string $identifier): bool
    {
        return $this->slug === $identifier || $this->id === $identifier || $this->name === $identifier;
    }
}
