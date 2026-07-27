<?php

declare(strict_types=1);

namespace Rowbase\Exception;

/**
 * 429 RATE_LIMIT_REACHED — plus de 5 appels par seconde pour ce jeton.
 *
 * Le client réessaie automatiquement (voir Config::$maxRetries) ; cette
 * exception n'est levée qu'une fois les tentatives épuisées.
 */
class RateLimitException extends ApiException
{
    /**
     * @param array<string,mixed>|null $body
     */
    public function __construct(
        string $message,
        int $statusCode,
        ?string $type = null,
        ?array $body = null,
        ?string $rawBody = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode, $type, $body, $rawBody);
    }
}
