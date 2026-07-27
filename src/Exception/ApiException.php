<?php

declare(strict_types=1);

namespace Rowbase\Exception;

use Rowbase\Http\Response;

/**
 * Réponse HTTP >= 400 renvoyée par l'API Rowbase.
 *
 * Le corps suit toujours la forme :
 *   { "error": { "type": "UNKNOWN_FIELD_NAME", "message": "…" } }
 */
class ApiException extends RowbaseException
{
    /**
     * @param array<string,mixed>|null $body Corps décodé de la réponse, si disponible.
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?string $type = null,
        public readonly ?array $body = null,
        public readonly ?string $rawBody = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    /**
     * Construit l'exception la plus spécifique possible à partir d'une réponse.
     */
    public static function fromResponse(Response $response): self
    {
        $body = $response->tryJson();
        $error = \is_array($body['error'] ?? null) ? $body['error'] : [];

        $type = \is_string($error['type'] ?? null) ? $error['type'] : null;
        $message = \is_string($error['message'] ?? null) && $error['message'] !== ''
            ? $error['message']
            : \sprintf('Rowbase a répondu HTTP %d.', $response->status);

        if ($type !== null) {
            $message = \sprintf('[%s] %s', $type, $message);
        }

        $class = match (true) {
            $type === 'RATE_LIMIT_REACHED', $response->status === 429 => RateLimitException::class,
            $type === 'AUTHENTICATION_REQUIRED', $type === 'INVALID_API_KEY', $response->status === 401 => AuthenticationException::class,
            $type === 'NOT_AUTHORIZED', $response->status === 403 => AuthorizationException::class,
            $response->status === 404 => NotFoundException::class,
            $response->status === 422, $response->status === 400 => ValidationException::class,
            $response->status >= 500 => ServerException::class,
            default => self::class,
        };

        if ($class === RateLimitException::class) {
            return new RateLimitException(
                $message,
                $response->status,
                $type,
                $body,
                $response->body,
                $response->retryAfterSeconds(),
            );
        }

        /** @var self $exception */
        $exception = new $class($message, $response->status, $type, $body, $response->body);

        return $exception;
    }
}
