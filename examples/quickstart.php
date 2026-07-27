<?php

declare(strict_types=1);

/**
 * Tour d'horizon du connecteur.
 *
 *     ROWBASE_API_KEY=rowb_… php examples/quickstart.php contacts sheet-1
 */

require __DIR__ . '/../vendor/autoload.php'; // ou __DIR__ . '/../autoload.php' sans Composer

use Rowbase\Client;
use Rowbase\Exception\RowbaseException;
use Rowbase\Query;

$tableSlug = $argv[1] ?? null;
$sheetSlug = $argv[2] ?? null;

try {
    $rowbase = Client::fromEnv();

    // 1. Le schéma : tables, feuilles, colonnes accessibles par le jeton.
    echo "Tables accessibles :\n";
    foreach ($rowbase->tables() as $table) {
        echo "  - {$table->name} ({$table->slug})\n";
        foreach ($table->sheets as $sheet) {
            echo "      {$sheet->name} ({$sheet->slug}) : " . \implode(', ', $sheet->fieldNames()) . "\n";
        }
    }

    if ($tableSlug === null || $sheetSlug === null) {
        echo "\nUsage : php examples/quickstart.php <table> <feuille>\n";
        exit(0);
    }

    $sheet = $rowbase->sheet($tableSlug, $sheetSlug);

    // 2. Lecture d'une page, triée.
    echo "\nCinq dernières lignes :\n";
    foreach ($sheet->list(Query::make()->pageSize(5)->sortDesc('Date')) as $record) {
        echo "  {$record->id} : " . \json_encode($record->fields, \JSON_UNESCAPED_UNICODE) . "\n";
    }

    // 3. Création.
    $created = $sheet->create([
        'ID' => 'demo-' . \bin2hex(\random_bytes(4)),
        'Website' => 'example.com',
    ]);
    echo "\nLigne créée : {$created->id}\n";

    // 4. Mise à jour partielle.
    $updated = $sheet->update($created->id, ['Website' => 'example.com']);
    echo "Mise à jour : " . \json_encode($updated->fields, \JSON_UNESCAPED_UNICODE) . "\n";

    // 5. Relecture puis suppression.
    echo "Relecture : {$sheet->find($created->id)->get('Website')}\n";
    echo $sheet->delete($created->id) ? "Ligne supprimée.\n" : "Suppression non confirmée.\n";
} catch (RowbaseException $e) {
    \fwrite(\STDERR, 'Erreur Rowbase : ' . $e->getMessage() . "\n");
    exit(1);
}
