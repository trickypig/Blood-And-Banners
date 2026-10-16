<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Conditions;

use BloodAndBanners\Rules\Vanilla\UnitConditions;

final class Enraged extends Condition
{
    public static function apply(array $state, string $unitId, int $level, array &$events): array
    {
        if ($level <= 0) {
            return UnitConditions::clear($state, $unitId, 'Enraged', $events);
        }
        return UnitConditions::apply($state, 'Enraged', $unitId, $unitId, ['kind' => 'untilCleared'], $events, ['level' => $level]);
    }

    public function statDelta(array $state, string $unitId, string $stat, array $context): int
    {
        return in_array($stat, ['power', 'defense'], true)
            ? (int)(UnitConditions::named($state, $unitId, 'Enraged')['data']['level'] ?? 0)
            : 0;
    }

    public function onTurnEnd(array $state, string $unitId, array &$events): array
    {
        if ($state['units'][$unitId]['seatId'] !== $state['turn']['activeSeatId']) {
            return $state;
        }
        $level = (int)(UnitConditions::named($state, $unitId, 'Enraged')['data']['level'] ?? 0);
        return self::apply($state, $unitId, $level - 1, $events);
    }
}
