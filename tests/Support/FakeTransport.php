<?php

declare(strict_types=1);

namespace Rowbase\Tests\Support;

use Rowbase\Exception\TransportException;
use Rowbase\Http\Request;
use Rowbase\Http\Response;
use Rowbase\Http\Transport;

/**
 * Transport de test : enregistre les requêtes et rejoue des réponses prédéfinies.
 */
final class FakeTransport implements Transport
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response|TransportException> */
    private array $queue = [];

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     */
    public function push(array $body, int $status = 200, array $headers = []): self
    {
        $this->queue[] = new Response($status, (string) \json_encode($body), $headers);

        return $this;
    }

    public function pushRaw(string $body, int $status = 200): self
    {
        $this->queue[] = new Response($status, $body);

        return $this;
    }

    public function pushFailure(string $message = 'boum'): self
    {
        $this->queue[] = new TransportException($message);

        return $this;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;

        $next = \array_shift($this->queue);

        if ($next === null) {
            throw new \RuntimeException(\sprintf(
                'Aucune réponse en file pour %s %s',
                $request->method,
                $request->url,
            ));
        }

        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): Request
    {
        $last = \end($this->requests);
        if (!$last instanceof Request) {
            throw new \RuntimeException('Aucune requête enregistrée.');
        }

        return $last;
    }

    /**
     * @return array<string,mixed>
     */
    public function bodyOf(int $index): array
    {
        $body = $this->requests[$index]->body ?? '';

        /** @var array<string,mixed> */
        return \json_decode($body === '' ? '{}' : $body, true, 512, \JSON_THROW_ON_ERROR);
    }

    public function count(): int
    {
        return \count($this->requests);
    }
}
