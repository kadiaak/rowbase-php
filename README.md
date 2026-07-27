# rowbase-php

Connecteur PHP pour l'[API REST Rowbase](https://rowbase.co/docs/reference/api).

Sans dépendance à l'exécution (ext-curl + ext-json), PHP >= 8.1. Il couvre l'intégralité de l'API — tables, lecture, création, mise à jour, remplacement, suppression — et gère pour vous la pagination, le découpage des lots de dix, la limite de cinq appels par seconde, les nouvelles tentatives et la traduction des erreurs en exceptions typées.

## Installation

```bash
composer require kadiaak/rowbase-php
```

Sans Composer :

```php
require __DIR__ . '/rowbase-php/autoload.php';
```

## Démarrage

```php
use Rowbase\Client;

$rowbase = new Client('rowb_…');          // ou Client::fromEnv() avec ROWBASE_API_KEY
$sheet   = $rowbase->sheet('contacts', 'sheet-1');

// Lire
foreach ($sheet->all() as $record) {
    echo $record['Email'], PHP_EOL;
}

// Créer
$record = $sheet->create([
    'ID'      => 'ref-2026-001',
    'Date'    => '2026-04-01 02:35:53',
    'Website' => 'example.com',
    'Email'   => 'contact@example.com',
]);

// Mettre à jour, puis supprimer
$sheet->update($record->id, ['Website' => 'example.com']);
$sheet->delete($record->id);
```

Le jeton se crée dans le panneau « API tokens » de Rowbase (réservé aux administrateurs) et peut être limité à certaines tables.

## Explorer le schéma

```php
foreach ($rowbase->tables() as $table) {
    echo $table->name, ' (', $table->slug, ')', PHP_EOL;

    foreach ($table->sheets as $sheet) {
        echo '  ', $sheet->name, ' : ', implode(', ', $sheet->fieldNames()), PHP_EOL;
    }
}

$type = $rowbase->table('contacts')->schema()->sheet('sheet-1')?->field('Email')?->type; // "email"
```

Le résultat de `tables()` est mis en cache en mémoire ; `tables(refresh: true)` force un nouvel appel.

Tables et feuilles s'adressent indifféremment par slug (`contacts`) ou par identifiant numérique (`1`).

## Lire des lignes

`Query` construit les paramètres de l'API de façon immuable :

```php
use Rowbase\Query;

$page = $sheet->list(
    Query::make()
        ->pageSize(50)              // 1 à 100
        ->fields('ID', 'Email')     // colonnes renvoyées
        ->sortDesc('Date')          // tris empilables
        ->maxRecords(200)
);

$page->records;      // list<Record>
$page->hasMore();    // reste-t-il des pages ?
$page->offset;       // curseur de la page suivante
```

Un tableau brut fonctionne aussi : `$sheet->list(['pageSize' => 50, 'sort' => [['field' => 'Date', 'direction' => 'desc']]])`.

### Pagination automatique

```php
// Ligne par ligne, toutes pages confondues (générateur : mémoire constante)
foreach ($sheet->all() as $record) { /* … */ }

// Page par page, pour traiter par lots de 100
foreach ($sheet->pages() as $page) {
    traiter($page->records);
}

// Tout charger d'un coup (attention à la volumétrie)
$records = $sheet->toArray(Query::make()->maxRecords(500));
```

### Une ligne précise

```php
$record = $sheet->find('0c8f2b16-1f3e-4a91-9a0c-2b7d0f5c8ab1');  // 404 => NotFoundException
$record = $sheet->findOrNull($id);                               // null si absente
$record = $sheet->first(Query::make()->sortDesc('Date'));        // la plus récente
```

### Filtrer

L'API Rowbase n'expose pas de filtre côté serveur : `where()` et `findBy()` parcourent les pages et filtrent en PHP. Sur une grande feuille, limitez d'abord les colonnes avec `Query::fields()`.

```php
$client = $sheet->findBy('Email', 'contact@example.com');

foreach ($sheet->where('Statut', 'à traiter') as $record) { /* … */ }
```

### L'objet `Record`

```php
$record->id;                     // UUID
$record->createdTime;            // ?DateTimeImmutable
$record->fields;                 // array<string,mixed>
$record['Email'];                // accès direct à une cellule
$record->get('Email', 'n/a');    // avec valeur par défaut
$record->with(['Statut' => 'ok']); // copie modifiée, prête pour updateMany()
```

## Écrire

```php
// Une ligne
$record = $sheet->create(['ID' => 'abc', 'Website' => 'example.com']);

// Plusieurs lignes — les lots de plus de dix sont découpés automatiquement
$records = $sheet->createMany([
    ['ID' => 'abc'],
    ['ID' => 'def'],
    // … autant de lignes que nécessaire
]);

// Mise à jour partielle (PATCH) : seules les cellules envoyées changent
$sheet->update($id, ['Website' => 'example.com']);

$sheet->updateMany([
    ['id' => $id1, 'fields' => ['Statut' => 'ok']],
    $id2 => ['Statut' => 'ko'],                      // map identifiant => cellules
    $record->with(['Statut' => 'ok']),               // objet Record
]);

// Remplacement complet (PUT) : les cellules non envoyées sont vidées
$sheet->replace($id, ['ID' => 'abc', 'Website' => 'example.com']);

// Suppression (dix identifiants par appel, découpage automatique)
$sheet->delete($id);
$supprimes = $sheet->deleteMany([$id1, $id2, $id3]);
```

Les valeurs sont converties par Rowbase selon le type de colonne, et les écritures déclenchent les synchronisations Google Sheets configurées.

## Erreurs

Toutes les exceptions dérivent de `Rowbase\Exception\RowbaseException`.

| Exception | Statut | Types d'erreur Rowbase |
|---|---|---|
| `AuthenticationException` | 401 | `AUTHENTICATION_REQUIRED`, `INVALID_API_KEY` |
| `AuthorizationException` | 403 | `NOT_AUTHORIZED` |
| `NotFoundException` | 404 | `TABLE_NOT_FOUND`, `SHEET_NOT_FOUND`, `ROW_NOT_FOUND` |
| `ValidationException` | 422 | `UNKNOWN_FIELD_NAME`, `TOO_MANY_RECORDS`, `INVALID_REQUEST_BODY` |
| `RateLimitException` | 429 | `RATE_LIMIT_REACHED` |
| `ServerException` | 5xx | — |
| `TransportException` | — | échec réseau, aucune réponse reçue |

```php
use Rowbase\Exception\ValidationException;

try {
    $sheet->create(['Colonne inexistante' => 1]);
} catch (ValidationException $e) {
    $e->type;        // "UNKNOWN_FIELD_NAME"
    $e->statusCode;  // 422
    $e->getMessage();// "[UNKNOWN_FIELD_NAME] Unknown field "Colonne inexistante"."
    $e->body;        // corps JSON décodé
}
```

## Limite de débit et nouvelles tentatives

Rowbase autorise cinq appels par seconde et par jeton. Le client applique un *token bucket* local à ce rythme, puis rejoue automatiquement :

- les `429`, en respectant l'en-tête `Retry-After` ;
- les `5xx` **sur les lectures seulement** (`GET`), pour ne jamais créer de doublon sur une écriture non idempotente ;
- les erreurs réseau.

Le backoff est exponentiel avec gigue, plafonné par `max_retry_delay`. Une fois les tentatives épuisées, l'exception typée est levée.

Le limiteur est propre à l'instance de `Client` : avec plusieurs processus partageant le même jeton, réduisez `requests_per_second` en conséquence.

## Configuration

```php
$rowbase = new Client('rowb_…', [
    'base_url'            => 'https://rowbase.co/api/v1',
    'timeout'             => 30.0,   // secondes
    'connect_timeout'     => 10.0,
    'max_retries'         => 3,
    'retry_delay'         => 0.5,    // délai initial du backoff
    'max_retry_delay'     => 8.0,
    'requests_per_second' => 5.0,    // 0 pour désactiver le throttling local
    'verify_ssl'          => true,
    'user_agent'          => 'mon-app/2.0',
    'transport'           => null,   // Rowbase\Http\Transport personnalisé
]);
```

`Client::fromEnv()` lit `ROWBASE_API_KEY` et, si elle existe, `ROWBASE_BASE_URL`.

### Brancher son propre client HTTP

```php
use Rowbase\Http\Psr18Transport;

$rowbase = new Client('rowb_…', [
    'transport' => new Psr18Transport($guzzle, $requestFactory, $streamFactory),
]);
```

Toute classe implémentant `Rowbase\Http\Transport` fait l'affaire (voir `tests/Support/FakeTransport.php` pour un exemple de double de test).

### Appels bruts

Pour un point d'entrée non couvert par la surface typée :

```php
$payload  = $rowbase->request('GET', '/tables');                       // tableau décodé
$response = $rowbase->send('GET', '/tables/contacts/sheet-1/records', ['pageSize' => 5]); // réponse HTTP
```

## Correspondance avec l'API

| Méthode PHP | Requête HTTP |
|---|---|
| `tables()` | `GET /tables` |
| `list()`, `all()`, `pages()`, `first()` | `GET /tables/{table}/{sheet}/records` |
| `find()`, `findOrNull()` | `GET /tables/{table}/{sheet}/records/{id}` |
| `create()`, `createMany()` | `POST /tables/{table}/{sheet}/records` |
| `update()`, `updateMany()` | `PATCH /tables/{table}/{sheet}/records` |
| `replace()`, `replaceMany()` | `PUT /tables/{table}/{sheet}/records` |
| `delete()`, `deleteMany()` | `DELETE /tables/{table}/{sheet}/records?records[]=…` |

## Tests

```bash
composer install
composer test
```

La suite tourne entièrement hors ligne grâce à `FakeTransport` : aucun appel réseau, aucun jeton nécessaire.

## Licence

MIT.
