<?php

declare(strict_types=1);

namespace Rowbase;

/**
 * Une page de résultats de GET /records.
 *
 * @implements \IteratorAggregate<int,Record>
 */
final class RecordPage implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Record>        $records
     * @param string|null         $offset  curseur de la page suivante (null = dernière page)
     * @param array<string,mixed> $raw     réponse brute décodée
     */
    public function __construct(
        public readonly array $records,
        public readonly ?string $offset = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $rows = \is_array($payload['records'] ?? null) ? $payload['records'] : [];
        $offset = $payload['offset'] ?? null;

        return new self(
            Record::listFromArray($rows),
            $offset === null || $offset === '' ? null : (string) $offset,
            $payload,
        );
    }

    public function hasMore(): bool
    {
        return $this->offset !== null;
    }

    public function first(): ?Record
    {
        return $this->records[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->records === [];
    }

    public function count(): int
    {
        return \count($this->records);
    }

    /**
     * @return \ArrayIterator<int,Record>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->records);
    }
}
