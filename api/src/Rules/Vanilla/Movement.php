<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\Vanilla\Conditions\Condition;

final class Movement
{
    /** Hexes the unit can end on, mapped to the steps it takes to get there. */
    public static function costs(array $state, string $unitId, ?int $budget = null): array
    {
        $unit = $state['units'][$unitId];
        $budget ??= Stats::effective($state, $unitId, 'move');
        $board = $state['board'];
        $occupants = self::occupants($state);
        $frontier = [$unit['hex']];
        $seen = [$unit['hex'] => true];
        $costs = [];
        for ($step = 1; $step <= $budget; $step++) {
            $next = [];
            foreach ($frontier as $from) {
                foreach (Hex::neighbors($from, $board) as $to) {
                    if (isset($seen[$to]) || $board[$to]['sightBlocker']) {
                        continue;
                    }
                    if (abs($board[$to]['elevation'] - $board[$from]['elevation']) > 1) {
                        continue;
                    }
                    $occupant = $occupants[$to] ?? null;
                    if ($occupant !== null && $occupant !== $unit['seatId']) {
                        continue;
                    }
                    $seen[$to] = true;
                    $next[] = $to;
                    if ($occupant === null) {
                        $costs[$to] = $step;
                    }
                }
            }
            $frontier = $next;
        }
        ksort($costs, SORT_STRING);
        return $costs;
    }

    public static function reachable(array $state, string $unitId, ?int $budget = null): array
    {
        return array_map('strval', array_keys(self::costs($state, $unitId, $budget)));
    }

    public static function disengagedFrom(array $state, string $unitId, string $to): array
    {
        $unit = $state['units'][$unitId];
        $board = $state['board'];
        $enemies = [];
        foreach ($state['units'] as $other) {
            if ($other['hex'] === null || $other['seatId'] === $unit['seatId']) {
                continue;
            }
            if (UnitConditions::any($state, $other['unitId'], static fn(Condition $condition): bool => $condition->blocksDisengagementStrike())) {
                continue;
            }
            $engaged = Hex::distance($unit['hex'], $other['hex']) === 1
                && abs($board[$unit['hex']]['elevation'] - $board[$other['hex']]['elevation']) <= 1;
            if ($engaged && Hex::distance($to, $other['hex']) !== 1) {
                $enemies[] = $other['unitId'];
            }
        }
        return $enemies;
    }

    private static function occupants(array $state): array
    {
        $occupants = [];
        foreach ($state['units'] as $unit) {
            if ($unit['hex'] !== null) {
                $occupants[$unit['hex']] = $unit['seatId'];
            }
        }
        return $occupants;
    }
}
