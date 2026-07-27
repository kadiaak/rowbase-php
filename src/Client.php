<?php

declare(strict_types=1);

namespace Rowbase;

use Rowbase\Exception\ApiException;
use Rowbase\Exception\RowbaseException;
use Rowbase\Exception\TransportException;
use Rowbase\Http\CurlTransport;
use Rowbase\Http\QueryString;
use Rowbase\Http\RateLimiter;
use Rowbase\Http\Request;
use Rowbase\Http\Response;
use Rowbase\Http\Transport;
use Rowbase\Schema\TableSchema;

/**
 * Point d'entrée du connecteur Rowbase.
 *
 *     $rowbase = new Rowbase\Client('rowb_…');
 *     $sheet   = $rowbase->sheet('contacts', 'sheet-1');
 *
 *     foreach ($sheet->all() as $record) {
 *         echo $record['Email'], PHP_EOL;
 *     }
 *
 * Le client gère l'authentification, la limite de 5 appels/seconde, les
 * nouvelles tentatives sur 429 / 5xx / erreur réseau, et la traduction des
 * erreurs de l'API en exceptions typées.
 */
final class Client
{
    public readonly Config $config;

    private readonly Transport $transport;

    private readonly ?RateLimiter $rateLimiter;

    /** @var list<TableSchema>|null */
    private ?array $tablesCache = null;

    /** @var callable(float):void */
    private $sleeper;

    /**
     * @param string                     $apiKey  jeton d'API (« rowb_… »)
     * @param Config|array<string,mixed> $config  options (voir Rowbase\Config)
     */
    public function __construct(
        private readonly string $apiKey,
        Config|array $config = [],
    ) {
        if (\trim($apiKey) === '') {
            throw new \InvalidArgumentException('Le jeton d\'API Rowbase est vide.');
        }

        $this->config = \is_array($config) ? Config::fromArray($config) : $config;

        $this->transport = $this->config->transport ?? new CurlTransport(
            timeout: $this->config->timeout,
            connectTimeout: $this->config->connectTimeout,
            verifySsl: $this->config->verifySsl,
        );

        $this->rateLimiter = $this->config->requestsPerSecond > 0
            ? new RateLimiter($this->config->requestsPerSecond)
            : null;

        $this->sleeper = static function (float $seconds): void {
            \usleep((int) \round($seconds * 1_000_000));
        };
    }

    /**
     * Construit un client à partir des variables d'environnement
     * ROWBASE_API_KEY et, optionnellement, ROWBASE_BASE_URL.
     *
     * @param array<string,mixed> $options
     */
    public static function fromEnv(array $options = []): self
    {
        $key = \getenv('ROWBASE_API_KEY');
        if ($key === false || \trim($key) === '') {
            throw new RowbaseException('La variable d\'environnement ROWBASE_API_KEY est absente.');
        }

        $baseUrl = \getenv('ROWBASE_BASE_URL');
        if ($baseUrl !== false && \trim($baseUrl) !== '' && !isset($options['base_url'])) {
            $options['base_url'] = $baseUrl;
        }

        return new self($key, $options);
    }

    /**
     * Remplace la fonction d'attente (backoff). Utile en test.
     *
     * @param callable(float):void $sleeper
     */
    public function setSleeper(callable $sleeper): void
    {
        $this->sleeper = $sleeper;
    }

    // ---------------------------------------------------------------- Schéma

    /**
     * GET /tables — toutes les tables accessibles par le jeton.
     *
     * @param bool $refresh ignore le cache mémoire
     *
     * @return list<TableSchema>
     */
    public function tables(bool $refresh = false): array
    {
        if ($this->tablesCache !== null && !$refresh) {
            return $this->tablesCache;
        }

        $payload = $this->request('GET', '/tables');

        $tables = [];
        foreach ((array) ($payload['tables'] ?? []) as $table) {
            if (\is_array($table)) {
                $tables[] = TableSchema::fromArray($table);
            }
        }

        return $this->tablesCache = $tables;
    }

    /**
     * Schéma d'une table précise (slug, ID ou nom), ou null si hors périmètre.
     */
    public function tableSchema(string $identifier, bool $refresh = false): ?TableSchema
    {
        foreach ($this->tables($refresh) as $table) {
            if ($table->matches($identifier)) {
                return $table;
            }
        }

        return null;
    }

    // -------------------------------------------------------------- Handles

    /**
     * Handle sur une table, adressée par slug ou par ID numérique.
     */
    public function table(string $table): Table
    {
        return new Table($this, $table);
    }

    /**
     * Raccourci : handle direct sur une feuille.
     */
    public function sheet(string $table, string $sheet): Sheet
    {
        return new Sheet($this, $table, $sheet);
    }

    // ------------------------------------------------------------ Bas niveau

    /**
     * Envoie une requête et renvoie le corps JSON décodé.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body
     *
     * @return array<string,mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        return $this->send($method, $path, $query, $body)->json();
    }

    /**
     * Idem, mais renvoie la réponse HTTP brute.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body
     */
    public function send(string $method, string $path, array $query = [], ?array $body = null): Response
    {
        $url = $this->config->baseUrl . '/' . \ltrim($path, '/');

        $queryString = QueryString::build($query);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->config->resolvedUserAgent(),
        ];

        $payload = null;
        if ($body !== null) {
            try {
                $payload = \json_encode(
                    $body,
                    \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
                );
            } catch (\JsonException $e) {
                throw new RowbaseException('Corps de requête non sérialisable en JSON : ' . $e->getMessage(), 0, $e);
            }
            $headers['Content-Type'] = 'application/json';
        }

        return $this->dispatch(new Request(\strtoupper($method), $url, $headers, $payload));
    }

    private function dispatch(Request $request): Response
    {
        $attempt = 0;

        while (true) {
            $this->rateLimiter?->acquire();

            try {
                $response = $this->transport->send($request);
            } catch (TransportException $e) {
                if ($attempt >= $this->config->maxRetries) {
                    throw $e;
                }
                $this->backoff($attempt++, null);

                continue;
            }

            if ($response->status < 400) {
                return $response;
            }

            $exception = ApiException::fromResponse($response);

            if ($attempt < $this->config->maxRetries && $this->isRetryable($response, $request)) {
                $this->backoff($attempt++, $response->retryAfterSeconds());

                continue;
            }

            throw $exception;
        }
    }

    private function isRetryable(Response $response, Request $request): bool
    {
        if ($response->status === 429) {
            return true;
        }

        // Les écritures ne sont pas idempotentes : on ne rejoue les 5xx que
        // pour les lectures, afin de ne pas créer de doublons.
        return $response->status >= 500
            && \in_array($request->method, ['GET', 'HEAD'], true);
    }

    private function backoff(int $attempt, ?float $retryAfter): void
    {
        $delay = $retryAfter ?? \min(
            $this->config->maxRetryDelay,
            $this->config->retryDelay * (2 ** $attempt),
        );

        // Un peu de gigue pour éviter que plusieurs workers repartent ensemble.
        $delay += \mt_rand(0, 100) / 1000;

        ($this->sleeper)(\min($delay, $this->config->maxRetryDelay + 1.0));
    }
}
