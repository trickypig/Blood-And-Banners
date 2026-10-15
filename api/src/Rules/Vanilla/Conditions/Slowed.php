<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Conditions;

final class Slowed extends Condition
{
    public function statDelta(array $state, string $unitId, string $stat, array $context): int
    {
        return $stat === 'move' ? -1 : 0;
    }
}
