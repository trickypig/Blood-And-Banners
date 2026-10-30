<?php
declare(strict_types=1);

namespace BloodAndBanners\Ai;

/**
 * One Void Lord personality: what it values, how it builds its horde, how sharp it plays and when it commits.
 *
 * weights:   damage, kill, loss, portalGuard, advance, cohesion, cover, threat, focus (numbers).
 * horde:     shares {common, elite, boss} (sum 1), placement (forward|portal|cover|combo),
 *            tag (swarm|fortress|ambush|combo), offBiome (0..1 share allowed outside the chosen biome).
 * sharpness: candidates (int), samples (int), blunder (0..1 chance of a random pick), pairs (bool two-unit lookahead).
 * stance:    {mode} plus mode-specific keys:
 *   ['mode' => 'attack']                                           always attack.
 *   ['mode' => 'hold', 'untilHeroWithin' => int, 'untilRound' => int]
 *        hold until a hero is within N hexes of any monster, or round > untilRound.
 *   ['mode' => 'holdUntilReach', 'untilRound' => int]
 *        hold until a hero is within reach (move + range) of a monster, or round > untilRound.
 *   ['mode' => 'adaptive', 'untilRound' => int, 'aggressive' => [weightKey => multiplier, ...]]
 *        patient until the void HP share exceeds the hero HP share, or round > untilRound; then
 *        the listed weights are multiplied by the given factors.
 */
final class Personality
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $difficulty,
        public readonly string $pitch,
        public readonly array $weights,
        public readonly array $horde,
        public readonly array $sharpness,
        public readonly array $stance,
    ) {
    }
}
