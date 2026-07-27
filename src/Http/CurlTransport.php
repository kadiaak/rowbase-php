<?php

declare(strict_types=1);

namespace Rowbase\Http;

use Rowbase\Exception\TransportException;

/**
 * Transport par défaut : ext-curl, sans dépendance externe.
 * Le handle cURL est réutilisé d'un appel à l'autre (keep-alive).
 */
final class CurlTransport implements Transport
{
    private ?\CurlHandle $handle = null;

    public function __construct(
        private readonly float $timeout = 30.0,
        private readonly float $connectTimeout = 10.0,
        private readonly bool $verifySsl = true,
    ) {
    }

    public function send(Request $request): Response
    {
        $curl = $this->handle ??= \curl_init();
        if ($curl === false) {
            throw new TransportException('Impossible d\'initialiser cURL.');
        }

        $responseHeaders = [];

        \curl_reset($curl);
        \curl_setopt_array($curl, [
            \CURLOPT_URL => $request->url,
            \CURLOPT_CUSTOMREQUEST => $request->method,
            \CURLOPT_HTTPHEADER => $request->headerLines(),
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_TIMEOUT_MS => (int) \round($this->timeout * 1000),
            \CURLOPT_CONNECTTIMEOUT_MS => (int) \round($this->connectTimeout * 1000),
            \CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            \CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            \CURLOPT_ACCEPT_ENCODING => '',
            \CURLOPT_HEADERFUNCTION => static function ($_curl, string $line) use (&$responseHeaders): int {
                $length = \strlen($line);
                $parts = \explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[\strtolower(\trim($parts[0]))] = \trim($parts[1]);
                }

                return $length;
            },
        ]);

        if ($request->body !== null) {
            \curl_setopt($curl, \CURLOPT_POSTFIELDS, $request->body);
        }

        $body = \curl_exec($curl);

        if ($body === false) {
            throw new TransportException(\sprintf(
                'Échec de la requête %s %s : %s (cURL #%d)',
                $request->method,
                $request->url,
                \curl_error($curl),
                \curl_errno($curl),
            ));
        }

        $status = (int) \curl_getinfo($curl, \CURLINFO_RESPONSE_CODE);

        return new Response($status, (string) $body, $responseHeaders);
    }

    public function __destruct()
    {
        if ($this->handle instanceof \CurlHandle) {
            \curl_close($this->handle);
        }
    }
}
