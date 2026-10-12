<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

/** A printed ability. Subclasses override only the hooks they need; every hook defaults to "no effect". */
abstract class Ability
{
    public function __construct(public readonly string $name)
    {
    }

    /** Additive change to $unitId's $stat caused by this ability on $ownerId. Context: attackerId, defenderId. */
    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        return 0;
    }

    public function attacksPerActivation(): int
    {
        return 1;
    }

    public function movesAfterAttack(): bool
    {
        return false;
    }

    public function evades(): bool
    {
        return false;
    }

    public function survivesDefeatOnce(): bool
    {
        return false;
    }

    public function locksSpellsWhenWounded(): bool
    {
        return false;
    }

    public function offersReroll(): bool
    {
        return false;
    }
}
