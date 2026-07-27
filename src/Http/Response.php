<?php

declare(strict_types=1);

namespace Rowbase\Http;

use Rowbase\Exception\RowbaseException;

/**
 * Réponse HTTP brute.
 */
final class Response
{
    /** @var array<string,string> en-têtes en minuscules */
    private array $normalizedHeaders;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[\strtolower((string) $name)] = $value;
        }
        $this->normalizedHeaders = $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->normalizedHeaders[\strtolower($name)] ?? null;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Corps décodé en tableau associatif.
     *
     * @return array<string,mixed>
     *
     * @throws RowbaseException si le corps n'est pas du JSON exploitable
     */
    public function json(): array
    {
        if (\trim($this->body) === '') {
            return [];
        }

        try {
            $decoded = \json_decode($this->body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RowbaseException(
                'Réponse Rowbase illisible (JSON invalide) : ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!\is_array($decoded)) {
            throw new RowbaseException('Réponse Rowbase inattendue : un objet JSON était attendu.');
        }

        return $decoded;
    }

    /**
     * Comme json(), mais renvoie null au lieu de lever une exception.
     *
     * @return array<string,mixed>|null
     */
    public function tryJson(): ?array
    {
        try {
            return $this->json();
        } catch (RowbaseException) {
            return null;
        }
    }

    /**
     * Valeur de l'en-tête Retry-After, en secondes (formats « delta » et date HTTP).
     */
    public function retryAfterSeconds(): ?float
    {
        $value = $this->header('retry-after');
        if ($value === null || $value === '') {
            return null;
        }

        if (\is_numeric($value)) {
            return \max(0.0, (float) $value);
        }

        $timestamp = \strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return \max(0.0, (float) ($timestamp - \time()));
    }
}
