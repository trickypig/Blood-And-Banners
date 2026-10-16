<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Conditions;

use BloodAndBanners\Rules\Vanilla\UnitConditions;

final class DragonSlayer extends Condition
{
    public function statDelta(array $state, string $unitId, string $stat, array $context): int
    {
        return in_array($stat, ['power', 'defense'], true)
            ? (int)(UnitConditions::named($state, $unitId, 'Dragon Slayer')['data']['level'] ?? 0)
            : 0;
    }
}
