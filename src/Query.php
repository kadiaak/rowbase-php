<?php

declare(strict_types=1);

namespace Rowbase;

/**
 * Constructeur immuable des paramètres de GET /records.
 *
 *     Query::make()
 *         ->fields('ID', 'Email')
 *         ->sort('Date', 'desc')
 *         ->pageSize(50)
 *         ->maxRecords(200);
 *
 * Rappel : l'API Rowbase ne propose pas de filtre côté serveur. Le filtrage se
 * fait côté client (voir Sheet::where() / Sheet::findBy()).
 */
final class Query
{
    public const ASC = 'asc';

    public const DESC = 'desc';

    public const MAX_PAGE_SIZE = 100;

    /**
     * @param list<string>                                     $fields
     * @param list<array{field:string,direction:string}>       $sort
     */
    private function __construct(
        private readonly ?int $pageSize = null,
        private readonly ?string $offset = null,
        private readonly ?int $maxRecords = null,
        private readonly array $fields = [],
        private readonly array $sort = [],
        private readonly ?bool $returnFieldsByFieldId = null,
    ) {
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * Accepte un Query, un tableau de paramètres, ou null.
     *
     * @param self|array<string,mixed>|null $query
     */
    public static function coerce(self|array|null $query): self
    {
        if ($query instanceof self) {
            return $query;
        }

        if ($query === null || $query === []) {
            return self::make();
        }

        $result = self::make();

        if (isset($query['pageSize']) || isset($query['page_size'])) {
            $result = $result->pageSize((int) ($query['pageSize'] ?? $query['page_size']));
        }
        if (isset($query['offset'])) {
            $result = $result->offset((string) $query['offset']);
        }
        if (isset($query['maxRecords']) || isset($query['max_records'])) {
            $result = $result->maxRecords((int) ($query['maxRecords'] ?? $query['max_records']));
        }
        if (isset($query['fields']) && \is_array($query['fields'])) {
            $result = $result->fields(...\array_map(strval(...), $query['fields']));
        }
        if (isset($query['sort']) && \is_array($query['sort'])) {
            foreach ($query['sort'] as $item) {
                if (\is_array($item) && isset($item['field'])) {
                    $result = $result->sort((string) $item['field'], (string) ($item['direction'] ?? self::ASC));
                } elseif (\is_string($item)) {
                    $result = $result->sort($item);
                }
            }
        }
        if (isset($query['returnFieldsByFieldId']) || isset($query['return_fields_by_field_id'])) {
            $result = $result->returnFieldsByFieldId(
                (bool) ($query['returnFieldsByFieldId'] ?? $query['return_fields_by_field_id']),
            );
        }

        return $result;
    }

    /**
     * Nombre de lignes par page (1 à 100, valeur par défaut de l'API : 100).
     */
    public function pageSize(int $size): self
    {
        if ($size < 1 || $size > self::MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException(
                \sprintf('pageSize doit être compris entre 1 et %d, %d reçu.', self::MAX_PAGE_SIZE, $size),
            );
        }

        return $this->copy(pageSize: $size);
    }

    /**
     * Curseur renvoyé par la page précédente.
     */
    public function offset(?string $offset): self
    {
        return $this->copy(offset: $offset, unset: $offset === null ? ['offset'] : []);
    }

    /**
     * Nombre total de lignes à renvoyer, toutes pages confondues.
     */
    public function maxRecords(int $max): self
    {
        if ($max < 1) {
            throw new \InvalidArgumentException('maxRecords doit être >= 1.');
        }

        return $this->copy(maxRecords: $max);
    }

    /**
     * Restreint les colonnes renvoyées (nom de colonne ou ID de champ).
     */
    public function fields(string ...$fields): self
    {
        return $this->copy(fields: [...$this->fields, ...\array_values($fields)]);
    }

    /**
     * Ajoute un critère de tri. Les appels successifs s'empilent.
     */
    public function sort(string $field, string $direction = self::ASC): self
    {
        $direction = \strtolower($direction);
        if (!\in_array($direction, [self::ASC, self::DESC], true)) {
            throw new \InvalidArgumentException('La direction de tri doit être « asc » ou « desc ».');
        }

        return $this->copy(sort: [...$this->sort, ['field' => $field, 'direction' => $direction]]);
    }

    public function sortDesc(string $field): self
    {
        return $this->sort($field, self::DESC);
    }

    /**
     * Indexe les cellules par ID de champ (fldXX) plutôt que par nom de colonne.
     */
    public function returnFieldsByFieldId(bool $enabled = true): self
    {
        return $this->copy(returnFieldsByFieldId: $enabled);
    }

    public function maxRecordsValue(): ?int
    {
        return $this->maxRecords;
    }

    public function pageSizeValue(): ?int
    {
        return $this->pageSize;
    }

    /**
     * Paramètres prêts pour Rowbase\Http\QueryString::build().
     *
     * @return array<string,mixed>
     */
    public function toParams(): array
    {
        $params = [];

        if ($this->pageSize !== null) {
            $params['pageSize'] = $this->pageSize;
        }
        if ($this->offset !== null) {
            $params['offset'] = $this->offset;
        }
        if ($this->maxRecords !== null) {
            $params['maxRecords'] = $this->maxRecords;
        }
        if ($this->fields !== []) {
            $params['fields'] = \array_values(\array_unique($this->fields));
        }
        if ($this->sort !== []) {
            $params['sort'] = $this->sort;
        }
        if ($this->returnFieldsByFieldId !== null) {
            $params['returnFieldsByFieldId'] = $this->returnFieldsByFieldId;
        }

        return $params;
    }

    /**
     * @param list<string>                              $fields
     * @param list<array{field:string,direction:string}> $sort
     * @param list<string>                              $unset
     */
    private function copy(
        ?int $pageSize = null,
        ?string $offset = null,
        ?int $maxRecords = null,
        ?array $fields = null,
        ?array $sort = null,
        ?bool $returnFieldsByFieldId = null,
        array $unset = [],
    ): self {
        return new self(
            pageSize: $pageSize ?? $this->pageSize,
            offset: \in_array('offset', $unset, true) ? null : ($offset ?? $this->offset),
            maxRecords: $maxRecords ?? $this->maxRecords,
            fields: $fields ?? $this->fields,
            sort: $sort ?? $this->sort,
            returnFieldsByFieldId: $returnFieldsByFieldId ?? $this->returnFieldsByFieldId,
        );
    }
}
