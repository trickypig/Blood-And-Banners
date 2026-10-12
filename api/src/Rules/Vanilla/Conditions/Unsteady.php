<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Conditions;

final class Unsteady extends Condition
{
    public function statDelta(array $state, string $unitId, string $stat, array $context): int
    {
        return $stat === 'power' ? -1 : 0;
    }

    public function blocksDisengagementStrike(): bool
    {
        return true;
    }
}
