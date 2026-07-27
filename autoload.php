<?php

declare(strict_types=1);

/**
 * Autoloader PSR-4 minimal, pour utiliser le connecteur sans Composer :
 *
 *     require __DIR__ . '/rowbase-php/autoload.php';
 *     $rowbase = new Rowbase\Client('rowb_…');
 *
 * Avec Composer, utilisez vendor/autoload.php à la place.
 */
\spl_autoload_register(static function (string $class): void {
    $prefix = 'Rowbase\\';

    if (!\str_starts_with($class, $prefix)) {
        return;
    }

    $relative = \substr($class, \strlen($prefix));
    $path = __DIR__ . '/src/' . \str_replace('\\', '/', $relative) . '.php';

    if (\is_file($path)) {
        require $path;
    }
});
