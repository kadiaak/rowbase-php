<?php

declare(strict_types=1);

namespace Rowbase\Http;

/**
 * Sérialisation des paramètres d'URL dans le format attendu par Rowbase :
 *
 *   ['fields' => ['ID', 'Email']]                        => fields[]=ID&fields[]=Email
 *   ['sort' => [['field' => 'Date', 'direction' => 'desc']]] => sort[0][field]=Date&sort[0][direction]=desc
 *   ['records' => ['uuid-1', 'uuid-2']]                  => records[]=uuid-1&records[]=uuid-2
 *
 * Les valeurs null sont omises, les booléens deviennent « true » / « false ».
 */
final class QueryString
{
    /**
     * @param array<string,mixed> $params
     */
    public static function build(array $params): string
    {
        $pairs = [];
        foreach (self::pairs($params, null) as [$key, $value]) {
            $pairs[] = \rawurlencode($key) . '=' . \rawurlencode($value);
        }

        return \implode('&', $pairs);
    }

    /**
     * @param array<array-key,mixed> $params
     *
     * @return list<array{0:string,1:string}>
     */
    private static function pairs(array $params, ?string $prefix): array
    {
        $out = [];

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            if ($prefix === null) {
                $name = (string) $key;
            } elseif (\is_int($key)) {
                // Liste de scalaires => clé[] ; liste d'objets => clé[i][sous-clé]
                $name = \is_array($value) ? $prefix . '[' . $key . ']' : $prefix . '[]';
            } else {
                $name = $prefix . '[' . $key . ']';
            }

            if (\is_array($value)) {
                foreach (self::pairs($value, $name) as $pair) {
                    $out[] = $pair;
                }

                continue;
            }

            $out[] = [$name, self::stringify($value)];
        }

        return $out;
    }

    private static function stringify(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return (string) \json_encode($value);
    }
}
