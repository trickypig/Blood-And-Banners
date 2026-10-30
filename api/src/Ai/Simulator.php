<?php
declare(strict_types=1);

namespace BloodAndBanners\Ai;

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use Throwable;

/**
 * Plays one candidate command forward on copies of the state, once per seeded roll sequence.
 * One instance serves one planning step (the TurnPlanner builds a fresh one each step).
 */
final class Simulator
{
    private bool $logged = false;

    public function __construct(private readonly MatchEngine $engine, private readonly int $now)
    {
    }

    /** @return list<array> resulting states, one per sample; [] when the command is illegal or the engine fails on it */
    public function outcomes(array $state, string $seatId, string $kind, array $payload, int $samples, int $seed): array
    {
        $states = [];
        for ($i = 0; $i < $samples; $i++) {
            try {
                $states[] = $this->engine->apply($state, $seatId, $kind, $payload, new SeededRandom($seed + $i), $this->now)['state'];
            } catch (GameServiceException) {
                return [];
            } catch (Throwable $error) {
                // An engine bug on one candidate must not sink the whole step: drop it, and say so once per step.
                if (!$this->logged) {
                    $this->logged = true;
                    error_log('[ai] candidate ' . $kind . ' dropped: ' . get_class($error) . ' ' . $error->getMessage()
                        . ' at ' . $error->getFile() . ':' . $error->getLine());
                }
                return [];
            }
        }
        return $states;
    }
}
