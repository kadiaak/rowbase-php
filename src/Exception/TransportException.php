<?php

declare(strict_types=1);

namespace Rowbase\Exception;

/**
 * Erreur réseau : DNS, TLS, timeout, connexion refusée…
 * Aucune réponse HTTP n'a été reçue.
 */
class TransportException extends RowbaseException
{
}
