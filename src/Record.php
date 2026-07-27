<?php

declare(strict_types=1);

namespace Rowbase;

/**
 * Une ligne renvoyée par l'API.
 *
 * Les cellules sont accessibles comme un tableau : $record['Email'].
 *
 * @implements \ArrayAccess<string,mixed>
 */
final class Record implements \ArrayAccess, \JsonSerializable
{
    /**
     * @param array<string,mixed> $fields cellules, indexées par nom de colonne
     *                                    (ou par ID de champ si returnFieldsByFieldId)
     */
    public function __construct(
        public readonly string $id,
        public readonly array $fields = [],
        public readonly ?\DateTimeImmutable $createdTime = null,
        public readonly bool $deleted = false,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $createdTime = null;
        if (\is_string($data['createdTime'] ?? null) && $data['createdTime'] !== '') {
            try {
                $createdTime = new \DateTimeImmutable($data['createdTime']);
            } catch (\Exception) {
                $createdTime = null;
            }
        }

        /** @var array<string,mixed> $fields */
        $fields = \is_array($data['fields'] ?? null) ? $data['fields'] : [];

        return new self(
            id: (string) ($data['id'] ?? ''),
            fields: $fields,
            createdTime: $createdTime,
            deleted: (bool) ($data['deleted'] ?? false),
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return list<self>
     */
    public static function listFromArray(array $rows): array
    {
        $records = [];
        foreach ($rows as $row) {
            if (\is_array($row)) {
                $records[] = self::fromArray($row);
            }
        }

        return $records;
    }

    public function get(string $field, mixed $default = null): mixed
    {
        return $this->fields[$field] ?? $default;
    }

    public function has(string $field): bool
    {
        return \array_key_exists($field, $this->fields);
    }

    /**
     * Copie du record avec des cellules modifiées (utile avant un update).
     *
     * @param array<string,mixed> $fields
     */
    public function with(array $fields): self
    {
        return new self($this->id, [...$this->fields, ...$fields], $this->createdTime, $this->deleted);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'createdTime' => $this->createdTime?->format('Y-m-d\TH:i:s.v\Z'),
            'fields' => $this->fields,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function offsetExists(mixed $offset): bool
    {
        return \array_key_exists((string) $offset, $this->fields);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->fields[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Un Record est immuable ; utilisez with() puis Sheet::update().');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Un Record est immuable ; utilisez with() puis Sheet::update().');
    }
}
