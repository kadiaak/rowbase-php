<?php

declare(strict_types=1);

namespace Rowbase\Exception;

/**
 * 5xx — erreur côté Rowbase. Rejouée automatiquement avant d'être levée.
 */
class ServerException extends ApiException
{
}
