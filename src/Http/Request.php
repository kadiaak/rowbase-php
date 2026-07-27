<?php

declare(strict_types=1);

namespace Rowbase\Http;

/**
 * Requête HTTP sortante, immuable.
 */
final class Request
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
    ) {
    }

    /**
     * En-têtes au format « Nom: valeur », prêts pour cURL.
     *
     * @return list<string>
     */
    public function headerLines(): array
    {
        $lines = [];
        foreach ($this->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }
}
