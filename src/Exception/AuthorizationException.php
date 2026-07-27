<?php

declare(strict_types=1);

namespace Rowbase\Exception;

/**
 * 403 NOT_AUTHORIZED — la table demandée est hors du périmètre du jeton.
 */
class AuthorizationException extends ApiException
{
}
