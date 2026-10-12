<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Conditions;

final class Stunned extends Condition
{
    public function statDelta(array $state, string $unitId, string $stat, array $context): int
    {
        return $stat === 'power' ? -2 : 0;
    }

    public function blocksMovement(): bool
    {
        return true;
    }

    public function blocksDisengagementStrike(): bool
    {
        return true;
    }
}
