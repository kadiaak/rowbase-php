<?php

declare(strict_types=1);

/**
 * Import d'un CSV dans une feuille Rowbase.
 *
 * La première ligne du CSV doit porter les noms de colonnes tels qu'ils
 * existent dans la feuille. Le connecteur découpe l'envoi en lots de dix et
 * respecte la limite de cinq appels par seconde.
 *
 *     ROWBASE_API_KEY=rowb_… php examples/import-csv.php clients.csv contacts sheet-1
 */

require __DIR__ . '/../vendor/autoload.php';

use Rowbase\Client;
use Rowbase\Exception\RowbaseException;
use Rowbase\Exception\ValidationException;

[$script, $csvPath, $tableSlug, $sheetSlug] = $argv + [null, null, null, null];

if ($csvPath === null || $tableSlug === null || $sheetSlug === null) {
    \fwrite(\STDERR, "Usage : php examples/import-csv.php <fichier.csv> <table> <feuille>\n");
    exit(1);
}

$handle = \fopen($csvPath, 'rb');
if ($handle === false) {
    \fwrite(\STDERR, "Fichier illisible : {$csvPath}\n");
    exit(1);
}

$rowbase = Client::fromEnv();
$sheet = $rowbase->sheet($tableSlug, $sheetSlug);

// Vérifie les en-têtes contre le schéma avant d'écrire quoi que ce soit.
$headers = \fgetcsv($handle);
if ($headers === false) {
    \fwrite(\STDERR, "CSV vide.\n");
    exit(1);
}

$known = $sheet->schema()->fieldNames();
$unknown = \array_diff($headers, $known);
if ($unknown !== []) {
    \fwrite(\STDERR, 'Colonnes absentes de la feuille : ' . \implode(', ', $unknown) . "\n");
    \fwrite(\STDERR, 'Colonnes disponibles : ' . \implode(', ', $known) . "\n");
    exit(1);
}

$batch = [];
$imported = 0;
$failed = 0;

$flush = static function () use ($sheet, &$batch, &$imported, &$failed): void {
    if ($batch === []) {
        return;
    }

    try {
        $imported += \count($sheet->createMany($batch));
    } catch (ValidationException $e) {
        // Un lot refusé : on repasse ligne par ligne pour isoler les fautives.
        foreach ($batch as $index => $row) {
            try {
                $sheet->create($row);
                ++$imported;
            } catch (RowbaseException $rowError) {
                ++$failed;
                \fwrite(\STDERR, "Ligne rejetée #{$index} : {$rowError->getMessage()}\n");
            }
        }
    }

    $batch = [];
};

while (($row = \fgetcsv($handle)) !== false) {
    if ($row === [null] || $row === []) {
        continue; // ligne vide
    }

    $fields = [];
    foreach ($headers as $i => $name) {
        $value = $row[$i] ?? '';
        if ($value !== '') {
            $fields[$name] = $value;
        }
    }

    if ($fields === []) {
        continue;
    }

    $batch[] = $fields;

    if (\count($batch) === 100) { // découpé ensuite en lots de dix par le connecteur
        $flush();
        echo "\r{$imported} lignes importées…";
    }
}

$flush();
\fclose($handle);

echo "\r{$imported} lignes importées, {$failed} rejetées.\n";
