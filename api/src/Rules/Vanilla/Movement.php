<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

final class Movement
{
    public static function reachable(array $state, string $unitId): array
    {
        $unit = $state['units'][$unitId];
        $board = $state['board'];
        $occupants = self::occupants($state);
        $frontier = [$unit['hex']];
        $seen = [$unit['hex'] => true];
        $reachable = [];
        for ($step = 0; $step < $unit['move']; $step++) {
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
                        $reachable[] = $to;
                    }
                }
            }
            $frontier = $next;
        }
        sort($reachable, SORT_STRING);
        return $reachable;
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
