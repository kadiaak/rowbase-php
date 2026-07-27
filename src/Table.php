<?php

declare(strict_types=1);

namespace Rowbase;

use Rowbase\Exception\NotFoundException;
use Rowbase\Schema\SheetSchema;
use Rowbase\Schema\TableSchema;

/**
 * Handle sur une table, adressée par slug (« contacts ») ou par ID (« 1 »).
 */
final class Table
{
    public function __construct(
        private readonly Client $client,
        public readonly string $identifier,
    ) {
    }

    /**
     * Handle sur une feuille de cette table.
     */
    public function sheet(string $sheet): Sheet
    {
        return new Sheet($this->client, $this->identifier, $sheet);
    }

    /**
     * Première feuille de la table — pratique quand il n'y en a qu'une.
     *
     * @throws NotFoundException si la table n'expose aucune feuille
     */
    public function firstSheet(): Sheet
    {
        $schema = $this->schema();
        $sheet = $schema->sheets[0] ?? null;

        if ($sheet === null) {
            throw new NotFoundException(
                \sprintf('La table « %s » ne contient aucune feuille.', $this->identifier),
                404,
                'SHEET_NOT_FOUND',
            );
        }

        return $this->sheet($sheet->slug !== '' ? $sheet->slug : $sheet->id);
    }

    /**
     * Schéma de la table (issu de GET /tables).
     *
     * @throws NotFoundException si la table est introuvable ou hors périmètre du jeton
     */
    public function schema(bool $refresh = false): TableSchema
    {
        $schema = $this->client->tableSchema($this->identifier, $refresh);

        if ($schema === null) {
            throw new NotFoundException(
                \sprintf('Table « %s » introuvable ou hors du périmètre du jeton.', $this->identifier),
                404,
                'TABLE_NOT_FOUND',
            );
        }

        return $schema;
    }

    /**
     * @return list<SheetSchema>
     */
    public function sheets(bool $refresh = false): array
    {
        return $this->schema($refresh)->sheets;
    }

    public function __toString(): string
    {
        return $this->identifier;
    }
}
