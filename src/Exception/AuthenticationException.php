<?php

declare(strict_types=1);

namespace Rowbase\Exception;

/**
 * 401 — en-tête Authorization manquant (AUTHENTICATION_REQUIRED)
 * ou jeton inconnu / révoqué (INVALID_API_KEY).
 */
class AuthenticationException extends ApiException
{
}
