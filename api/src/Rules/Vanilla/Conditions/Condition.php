<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Conditions;

/** A named state on a unit (Stunned now; Unsteady, Slipping, Poisoned later). Hooks default to "no effect". */
abstract class Condition
{
    public function __construct(public readonly string $name)
    {
    }

    public function statDelta(array $state, string $unitId, string $stat, array $context): int
    {
        return 0;
    }

    public function blocksMovement(): bool
    {
        return false;
    }

    public function blocksAttacks(): bool
    {
        return false;
    }

    public function blocksDefense(): bool
    {
        return false;
    }

    public function blocksDisengagementStrike(): bool
    {
        return false;
    }

    public function onTurnStart(array $state, string $unitId, array &$events): array
    {
        return $state;
    }

    public function onTurnEnd(array $state, string $unitId, array &$events): array
    {
        return $state;
    }
}
