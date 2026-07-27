<?php

declare(strict_types=1);

namespace Rowbase;

use Rowbase\Http\Transport;

/**
 * Configuration du client. Toutes les options ont une valeur par défaut saine.
 */
final class Config
{
    public const DEFAULT_BASE_URL = 'https://rowbase.co/api/v1';

    public const VERSION = '1.0.0';

    /**
     * @param string         $baseUrl          racine de l'API (sans slash final)
     * @param float          $timeout          délai total maximum d'une requête, en secondes
     * @param float          $connectTimeout   délai de connexion maximum, en secondes
     * @param int            $maxRetries       tentatives supplémentaires sur 429 / 5xx / erreur réseau
     * @param float          $retryDelay       délai initial du backoff exponentiel, en secondes
     * @param float          $maxRetryDelay    plafond du backoff, en secondes
     * @param float          $requestsPerSecond limite locale ; 0 pour désactiver le throttling
     * @param Transport|null $transport        transport personnalisé (PSR-18, mock…)
     */
    public function __construct(
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
        public readonly float $timeout = 30.0,
        public readonly float $connectTimeout = 10.0,
        public readonly int $maxRetries = 3,
        public readonly float $retryDelay = 0.5,
        public readonly float $maxRetryDelay = 8.0,
        public readonly float $requestsPerSecond = 5.0,
        public readonly bool $verifySsl = true,
        public readonly ?string $userAgent = null,
        public readonly ?Transport $transport = null,
    ) {
    }

    /**
     * @param array<string,mixed> $options clés en snake_case ou camelCase
     */
    public static function fromArray(array $options): self
    {
        $get = static function (string $snake, mixed $default) use ($options): mixed {
            $camel = \lcfirst(\str_replace(' ', '', \ucwords(\str_replace('_', ' ', $snake))));

            return $options[$snake] ?? $options[$camel] ?? $default;
        };

        $transport = $get('transport', null);
        if ($transport !== null && !$transport instanceof Transport) {
            throw new \InvalidArgumentException('L\'option « transport » doit implémenter Rowbase\Http\Transport.');
        }

        return new self(
            baseUrl: \rtrim((string) $get('base_url', self::DEFAULT_BASE_URL), '/'),
            timeout: (float) $get('timeout', 30.0),
            connectTimeout: (float) $get('connect_timeout', 10.0),
            maxRetries: (int) $get('max_retries', 3),
            retryDelay: (float) $get('retry_delay', 0.5),
            maxRetryDelay: (float) $get('max_retry_delay', 8.0),
            requestsPerSecond: (float) $get('requests_per_second', 5.0),
            verifySsl: (bool) $get('verify_ssl', true),
            userAgent: $get('user_agent', null) !== null ? (string) $get('user_agent', null) : null,
            transport: $transport,
        );
    }

    public function resolvedUserAgent(): string
    {
        return $this->userAgent ?? \sprintf('rowbase-php/%s (PHP %s)', self::VERSION, \PHP_VERSION);
    }
}
