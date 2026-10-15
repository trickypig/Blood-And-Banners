<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Conditions;

use BloodAndBanners\Rules\Vanilla\UnitConditions;

final class Stunned extends Condition
{
    /** Refresh stun without stacking penalties or weakening an existing stronger stun. */
    public static function apply(array $state, string $unitId, string $sourceUnitId, array &$events, int $powerPenalty = 2): array
    {
        $existing = UnitConditions::named($state, $unitId, 'Stunned');
        if ($existing !== null) {
            $powerPenalty = max($powerPenalty, $existing['data']['powerPenalty'] ?? 2);
        }
        return UnitConditions::apply(
            $state, 'Stunned', $unitId, $sourceUnitId, ['kind' => 'turnEnd', 'seatId' => $state['units'][$unitId]['seatId']], $events,
            $powerPenalty === 2 ? [] : ['powerPenalty' => $powerPenalty]
        );
    }

    public function statDelta(array $state, string $unitId, string $stat, array $context): int
    {
        return $stat === 'power' ? -(UnitConditions::named($state, $unitId, 'Stunned')['data']['powerPenalty'] ?? 2) : 0;
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
