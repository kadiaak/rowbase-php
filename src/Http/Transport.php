<?php

declare(strict_types=1);

namespace Rowbase\Http;

use Rowbase\Exception\TransportException;

/**
 * Couche d'envoi HTTP. Implémentez-la pour brancher votre propre client
 * (Guzzle, Symfony HttpClient, mock de test…).
 */
interface Transport
{
    /**
     * @throws TransportException en cas d'échec réseau (aucune réponse reçue)
     */
    public function send(Request $request): Response;
}
