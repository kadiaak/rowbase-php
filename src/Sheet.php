<?php

declare(strict_types=1);

namespace Rowbase;

use Rowbase\Exception\NotFoundException;
use Rowbase\Exception\RowbaseException;
use Rowbase\Schema\SheetSchema;

/**
 * Handle sur une feuille : c'est ici que vivent les opérations CRUD.
 *
 *     $sheet = $rowbase->sheet('contacts', 'sheet-1');
 *
 *     $page   = $sheet->list(Query::make()->pageSize(50)->sortDesc('Date'));
 *     $record = $sheet->create(['ID' => 'abc', 'Website' => 'example.com']);
 *     $sheet->update($record->id, ['Website' => 'example.com']);
 *     $sheet->delete($record->id);
 */
final class Sheet
{
    /**
     * L'API accepte au maximum dix lignes par appel de création, de mise à jour
     * ou de suppression. Le connecteur découpe automatiquement les lots.
     */
    public const MAX_BATCH_SIZE = 10;

    public function __construct(
        private readonly Client $client,
        public readonly string $table,
        public readonly string $sheet,
    ) {
    }

    // ------------------------------------------------------------- Lecture

    /**
     * GET /records — une page de résultats.
     *
     * @param Query|array<string,mixed>|null $query
     */
    public function list(Query|array|null $query = null): RecordPage
    {
        $payload = $this->client->request('GET', $this->path(), Query::coerce($query)->toParams());

        return RecordPage::fromArray($payload);
    }

    /**
     * Parcourt toutes les pages et renvoie chaque ligne, une par une.
     * La pagination est gérée automatiquement via le curseur « offset ».
     *
     * @param Query|array<string,mixed>|null $query
     *
     * @return \Generator<int,Record>
     */
    public function all(Query|array|null $query = null): \Generator
    {
        $query = Query::coerce($query);
        $limit = $query->maxRecordsValue();
        $yielded = 0;

        foreach ($this->pages($query) as $page) {
            foreach ($page as $record) {
                yield $record;

                if ($limit !== null && ++$yielded >= $limit) {
                    return;
                }
            }
        }
    }

    /**
     * Comme all(), mais renvoie un tableau. Attention à la volumétrie.
     *
     * @param Query|array<string,mixed>|null $query
     *
     * @return list<Record>
     */
    public function toArray(Query|array|null $query = null): array
    {
        return \iterator_to_array($this->all($query), false);
    }

    /**
     * Parcourt les pages brutes (utile pour traiter par lots de 100).
     *
     * @param Query|array<string,mixed>|null $query
     *
     * @return \Generator<int,RecordPage>
     */
    public function pages(Query|array|null $query = null): \Generator
    {
        $query = Query::coerce($query);
        $seen = [];

        while (true) {
            $page = $this->list($query);

            yield $page;

            $offset = $page->offset;
            if ($offset === null || $page->isEmpty()) {
                return;
            }

            // Garde-fou : un curseur répété signalerait une boucle infinie.
            if (isset($seen[$offset])) {
                return;
            }
            $seen[$offset] = true;

            $query = $query->offset($offset);
        }
    }

    /**
     * Première ligne correspondant à la requête, ou null.
     *
     * @param Query|array<string,mixed>|null $query
     */
    public function first(Query|array|null $query = null): ?Record
    {
        return $this->list(Query::coerce($query)->pageSize(1))->first();
    }

    /**
     * GET /records/{id}.
     *
     * @throws NotFoundException si la ligne n'existe pas
     */
    public function find(string $recordId): Record
    {
        $payload = $this->client->request('GET', $this->path() . '/' . self::segment($recordId));

        return $this->recordFrom($payload);
    }

    /**
     * Comme find(), mais renvoie null au lieu de lever une 404.
     */
    public function findOrNull(string $recordId): ?Record
    {
        try {
            return $this->find($recordId);
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * Lignes dont la colonne $field vaut $value.
     *
     * L'égalité est poussée côté serveur via Query::filter(), ce qui évite de
     * rapatrier toute la feuille. La comparaison est aussi refaite en mémoire :
     * c'est un garde-fou si l'API ne connaît pas encore le filtre serveur (elle
     * renvoie alors toute la feuille) et si elle compare différemment du strict
     * PHP. Sur une grosse feuille, restreignez les colonnes via Query::fields().
     *
     * @param Query|array<string,mixed>|null $query
     *
     * @return \Generator<int,Record>
     */
    public function where(string $field, mixed $value, Query|array|null $query = null): \Generator
    {
        foreach ($this->all(Query::coerce($query)->filter($field, $value)) as $record) {
            if ($record->get($field) === $value) {
                yield $record;
            }
        }
    }

    /**
     * Vrai dès qu'une ligne porte $value dans la colonne $field.
     *
     * Une seule requête : l'égalité est évaluée côté serveur et seule une
     * ligne est demandée. À préférer à un parcours complet quand on cherche
     * juste à savoir si une valeur existe déjà (test d'unicité avant insertion).
     *
     * La valeur renvoyée est revérifiée : si l'API ne connaît pas encore le
     * filtre serveur, elle renverrait une ligne quelconque — on refuse ce faux
     * positif plutôt que de laisser croire à une existence.
     */
    public function exists(string $field, mixed $value): bool
    {
        $record = $this->list(Query::make()->filter($field, $value)->fields($field)->pageSize(1))->first();

        return $record !== null && $record->get($field) === $value;
    }

    /**
     * Première ligne dont la colonne vaut $value, ou null. Voir where().
     *
     * @param Query|array<string,mixed>|null $query
     */
    public function findBy(string $field, mixed $value, Query|array|null $query = null): ?Record
    {
        foreach ($this->where($field, $value, $query) as $record) {
            return $record;
        }

        return null;
    }

    /**
     * Nombre total de lignes. Coûteux : une requête par page de 100.
     */
    public function count(): int
    {
        $total = 0;
        foreach ($this->pages(Query::make()->pageSize(Query::MAX_PAGE_SIZE)) as $page) {
            $total += $page->count();
        }

        return $total;
    }

    // ------------------------------------------------------------ Écriture

    /**
     * POST /records — une ligne.
     *
     * @param array<string,mixed> $fields cellules, indexées par nom de colonne
     */
    public function create(array $fields): Record
    {
        $payload = $this->client->request('POST', $this->path(), [], ['fields' => $fields]);

        return $this->recordFrom($payload);
    }

    /**
     * POST /records — plusieurs lignes. Les lots de plus de dix lignes sont
     * découpés automatiquement en plusieurs appels.
     *
     * @param array<int,array<string,mixed>|Record> $rows tableaux de cellules,
     *                                                    ou entrées ['fields' => [...]]
     *
     * @return list<Record>
     */
    public function createMany(array $rows): array
    {
        $created = [];

        foreach (\array_chunk(\array_values($rows), self::MAX_BATCH_SIZE) as $chunk) {
            $body = ['records' => \array_map(
                static fn (array|Record $row): array => ['fields' => self::extractFields($row)],
                $chunk,
            )];

            $payload = $this->client->request('POST', $this->path(), [], $body);

            foreach ($this->recordsFrom($payload) as $record) {
                $created[] = $record;
            }
        }

        return $created;
    }

    /**
     * PATCH /records — met à jour uniquement les cellules envoyées.
     *
     * @param array<string,mixed> $fields
     */
    public function update(string $recordId, array $fields): Record
    {
        $records = $this->updateMany([['id' => $recordId, 'fields' => $fields]]);

        return $records[0] ?? throw new RowbaseException('La mise à jour n\'a renvoyé aucune ligne.');
    }

    /**
     * PATCH /records — mise à jour par lot (dix lignes maximum par appel,
     * découpage automatique au-delà).
     *
     * Formats acceptés :
     *   [['id' => 'uuid', 'fields' => ['Website' => 'x']], …]
     *   ['uuid' => ['Website' => 'x'], …]
     *   [$record->with(['Website' => 'x']), …]
     *
     * @param array<array-key,array<string,mixed>|Record> $updates
     *
     * @return list<Record>
     */
    public function updateMany(array $updates): array
    {
        return $this->writeMany('PATCH', $updates);
    }

    /**
     * PUT /records — remplace la ligne entière : les cellules non envoyées
     * sont vidées.
     *
     * @param array<string,mixed> $fields
     */
    public function replace(string $recordId, array $fields): Record
    {
        $records = $this->replaceMany([['id' => $recordId, 'fields' => $fields]]);

        return $records[0] ?? throw new RowbaseException('Le remplacement n\'a renvoyé aucune ligne.');
    }

    /**
     * PUT /records — remplacement par lot. Voir updateMany() pour les formats.
     *
     * @param array<array-key,array<string,mixed>|Record> $updates
     *
     * @return list<Record>
     */
    public function replaceMany(array $updates): array
    {
        return $this->writeMany('PUT', $updates);
    }

    /**
     * DELETE /records?records[]=… — supprime une ligne.
     *
     * @return bool true si l'API a confirmé la suppression
     */
    public function delete(string $recordId): bool
    {
        return \in_array($recordId, $this->deleteMany([$recordId]), true);
    }

    /**
     * DELETE /records — suppression par lot (dix identifiants maximum par
     * appel, découpage automatique au-delà).
     *
     * @param array<int,string|Record> $recordIds
     *
     * @return list<string> identifiants effectivement supprimés
     */
    public function deleteMany(array $recordIds): array
    {
        $ids = \array_values(\array_map(
            static fn (string|Record $id): string => $id instanceof Record ? $id->id : $id,
            $recordIds,
        ));

        $deleted = [];

        foreach (\array_chunk($ids, self::MAX_BATCH_SIZE) as $chunk) {
            $payload = $this->client->request('DELETE', $this->path(), ['records' => $chunk]);

            foreach ($this->recordsFrom($payload) as $record) {
                if ($record->id !== '') {
                    $deleted[] = $record->id;
                }
            }
        }

        return $deleted;
    }

    // -------------------------------------------------------------- Schéma

    /**
     * Schéma de la feuille (colonnes et types), issu de GET /tables.
     *
     * @throws NotFoundException si la feuille est introuvable
     */
    public function schema(bool $refresh = false): SheetSchema
    {
        $table = $this->client->tableSchema($this->table, $refresh);
        $sheet = $table?->sheet($this->sheet);

        if ($sheet === null) {
            throw new NotFoundException(
                \sprintf('Feuille « %s » introuvable dans la table « %s ».', $this->sheet, $this->table),
                404,
                'SHEET_NOT_FOUND',
            );
        }

        return $sheet;
    }

    /**
     * Chemin de l'API pour cette feuille.
     */
    public function path(): string
    {
        return \sprintf('/tables/%s/%s/records', self::segment($this->table), self::segment($this->sheet));
    }

    // ------------------------------------------------------------- Interne

    /**
     * @param array<array-key,array<string,mixed>|Record> $updates
     *
     * @return list<Record>
     */
    private function writeMany(string $method, array $updates): array
    {
        $normalized = [];

        foreach ($updates as $key => $update) {
            $id = self::extractId($update, \is_string($key) ? $key : null);

            if ($id === null || $id === '') {
                throw new \InvalidArgumentException(
                    'Chaque ligne à mettre à jour doit porter un identifiant (clé « id »).',
                );
            }

            $normalized[] = ['id' => $id, 'fields' => self::extractFields($update)];
        }

        $result = [];

        foreach (\array_chunk($normalized, self::MAX_BATCH_SIZE) as $chunk) {
            $payload = $this->client->request($method, $this->path(), [], ['records' => $chunk]);

            foreach ($this->recordsFrom($payload) as $record) {
                $result[] = $record;
            }
        }

        return $result;
    }

    /**
     * Les écritures peuvent répondre soit { "records": [...] }, soit
     * directement l'objet ligne : on accepte les deux formes.
     *
     * @param array<string,mixed> $payload
     *
     * @return list<Record>
     */
    private function recordsFrom(array $payload): array
    {
        if (\is_array($payload['records'] ?? null)) {
            return Record::listFromArray($payload['records']);
        }

        if (isset($payload['record']) && \is_array($payload['record'])) {
            return [Record::fromArray($payload['record'])];
        }

        if (isset($payload['id'])) {
            return [Record::fromArray($payload)];
        }

        return [];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function recordFrom(array $payload): Record
    {
        $records = $this->recordsFrom($payload);

        return $records[0] ?? throw new RowbaseException('Réponse Rowbase inattendue : aucune ligne renvoyée.');
    }

    /**
     * @param array<string,mixed>|Record $row
     *
     * @return array<string,mixed>
     */
    private static function extractFields(array|Record $row): array
    {
        if ($row instanceof Record) {
            return $row->fields;
        }

        if (\is_array($row['fields'] ?? null)) {
            /** @var array<string,mixed> */
            return $row['fields'];
        }

        $fields = $row;
        unset($fields['id']);

        return $fields;
    }

    /**
     * @param array<string,mixed>|Record $row
     */
    private static function extractId(array|Record $row, ?string $fallback = null): ?string
    {
        if ($row instanceof Record) {
            return $row->id;
        }

        if (isset($row['id']) && \is_scalar($row['id'])) {
            return (string) $row['id'];
        }

        return $fallback;
    }

    private static function segment(string $value): string
    {
        return \rawurlencode($value);
    }

    public function __toString(): string
    {
        return $this->table . '/' . $this->sheet;
    }
}
