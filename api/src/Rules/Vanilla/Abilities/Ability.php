<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\RandomSource;

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

    /** Total attack budget at current health. Context distinguishes a requested or previously used special attack. */
    public function attacksPerActivation(array $unit = [], array $context = []): int
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

    public function ignoresTerrain(string $terrain, string $movementKind): bool
    {
        return false;
    }

    public function defenseDeltaForDamageType(string $damageType): int
    {
        return 0;
    }

    /** After a strike lands on a surviving target, including a blocked strike with zero wounds. */
    public function afterHit(array $state, string $ownerId, string $targetId, int $wounds = 0): array
    {
        return [];
    }

    /** What happens when the target of this ability's save fails it. */
    public function onFailedSave(array $state, string $ownerId, string $targetId, int $roll, RandomSource $random, array &$events): array
    {
        return $state;
    }

    /** Death reactions run after removal, before victory, with the original position preserved. */
    public function onDefeat(array $state, string $unitId, array $defeated, ?string $attackerId, ?RandomSource $random, array &$events): array
    {
        return $state;
    }
}
