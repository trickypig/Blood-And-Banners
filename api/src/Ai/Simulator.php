<?php
declare(strict_types=1);

namespace BloodAndBanners\Ai;

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\Vanilla\MatchEngine;

/** Plays one candidate command forward on copies of the state, once per seeded roll sequence. */
final class Simulator
{
    public function __construct(private readonly MatchEngine $engine, private readonly int $now)
    {
    }

    /** @return list<array> resulting states, one per sample; [] when the command is illegal */
    public function outcomes(array $state, string $seatId, string $kind, array $payload, int $samples, int $seed): array
    {
        $states = [];
        for ($i = 0; $i < $samples; $i++) {
            try {
                $states[] = $this->engine->apply($state, $seatId, $kind, $payload, new SeededRandom($seed + $i), $this->now)['state'];
            } catch (GameServiceException) {
                return [];
            }
        }
        return $states;
    }
}
