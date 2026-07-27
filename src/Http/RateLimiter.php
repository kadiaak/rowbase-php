<?php

declare(strict_types=1);

namespace Rowbase\Http;

/**
 * Limiteur de débit local (token bucket), pour rester sous la limite Rowbase
 * de cinq appels par seconde et par jeton.
 *
 * Il est propre à l'instance de Client : si plusieurs processus utilisent le
 * même jeton en parallèle, la limite reste atteignable — les 429 sont alors
 * rattrapés par le mécanisme de retry du Client.
 */
final class RateLimiter
{
    private float $tokens;

    private float $updatedAt;

    /** @var callable(float):void */
    private $sleeper;

    /**
     * @param float                       $rate    requêtes autorisées par seconde
     * @param float|null                  $burst   taille du seau (par défaut : $rate)
     * @param (callable(float):void)|null $sleeper injectable pour les tests
     */
    public function __construct(
        private readonly float $rate,
        ?float $burst = null,
        ?callable $sleeper = null,
    ) {
        $this->tokens = $burst ?? $rate;
        $this->updatedAt = \microtime(true);
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            \usleep((int) \round($seconds * 1_000_000));
        };
    }

    /**
     * Bloque le temps nécessaire pour consommer un jeton.
     */
    public function acquire(): void
    {
        if ($this->rate <= 0.0) {
            return;
        }

        $now = \microtime(true);
        $this->tokens = \min($this->rate, $this->tokens + ($now - $this->updatedAt) * $this->rate);
        $this->updatedAt = $now;

        if ($this->tokens < 1.0) {
            $wait = (1.0 - $this->tokens) / $this->rate;
            ($this->sleeper)($wait);
            $this->updatedAt += $wait;
            $this->tokens = 1.0;
        }

        --$this->tokens;
    }
}
