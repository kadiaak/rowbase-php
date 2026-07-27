<?php

declare(strict_types=1);

namespace Rowbase\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rowbase\Exception\TransportException;

/**
 * Transport basé sur un client PSR-18 (Guzzle, Symfony HttpClient, …).
 *
 * Nécessite psr/http-client et psr/http-factory :
 *
 *     $client = new Rowbase\Client($token, [
 *         'transport' => new Psr18Transport($guzzle, $requestFactory, $streamFactory),
 *     ]);
 */
final class Psr18Transport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function send(Request $request): Response
    {
        $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);

        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }

        if ($request->body !== null) {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->body));
        }

        try {
            $psrResponse = $this->client->sendRequest($psrRequest);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException(\sprintf(
                'Échec de la requête %s %s : %s',
                $request->method,
                $request->url,
                $e->getMessage(),
            ), 0, $e);
        }

        $headers = [];
        foreach ($psrResponse->getHeaders() as $name => $values) {
            $headers[$name] = \implode(', ', $values);
        }

        return new Response(
            $psrResponse->getStatusCode(),
            (string) $psrResponse->getBody(),
            $headers,
        );
    }
}
