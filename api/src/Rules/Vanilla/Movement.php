<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\Vanilla\Conditions\Condition;

final class Movement
{
    public static function options(array $state, string $unitId, ?int $budget = null): array
    {
        $unit = $state['units'][$unitId];
        if ($unit['hex'] === null) {
            return [];
        }
        $budget ??= Stats::effective($state, $unitId, 'move');
        $occupants = self::occupants($state);
        $frontier = [['hex' => $unit['hex'], 'path' => [], 'cost' => 0, 'hazards' => [], 'damage' => 0, 'visited' => [$unit['hex'] => true]]];
        $bestState = [$unit['hex'] => [0, 0, '']];
        $options = [];
        while ($frontier !== []) {
            usort($frontier, static fn(array $left, array $right): int => self::rank($left) <=> self::rank($right));
            $current = array_shift($frontier);
            $neighbors = Hex::neighbors($current['hex'], $state['board']);
            sort($neighbors, SORT_STRING);
            foreach ($neighbors as $next) {
                if (isset($current['visited'][$next])) {
                    continue;
                }
                $occupant = $occupants[$next] ?? null;
                if ($occupant !== null && Sides::enemies($state, $unitId, $occupant['unitId'])) {
                    continue;
                }
                if (!Terrain::canEnter($state, $unitId, $current['hex'], $next, 'voluntary')) {
                    continue;
                }
                $stepCost = Terrain::stepCost($state, $unitId, $current['hex'], $next, 'voluntary');
                if ($current['cost'] + $stepCost > $budget) {
                    continue;
                }
                $candidate = $current;
                $candidate['hex'] = $next;
                $candidate['path'][] = $next;
                $candidate['cost'] += $stepCost;
                $candidate['visited'][$next] = true;
                $hazards = Terrain::hazards($state, $unitId, $next, 'voluntary');
                $candidate['hazards'] = [...$candidate['hazards'], ...$hazards];
                $candidate['damage'] += array_sum(array_column($hazards, 'dice'));
                $terminal = Terrain::stopsVoluntary($state, $unitId, $next);
                if (Terrain::type($state['board'][$next]) === 'ice' && !Terrain::ignored($state, $unitId, 'ice', 'voluntary')) {
                    $candidate = self::projectSlide($state, $unitId, $candidate, $current['hex'], $occupants, $budget);
                    $terminal = true;
                }
                $finalOccupant = $occupants[$candidate['hex']] ?? null;
                if ($finalOccupant === null && Terrain::canEnd($state, $unitId, $candidate['hex'], 'voluntary')) {
                    $option = ['destination' => $candidate['hex'], 'path' => $candidate['path'], 'cost' => $candidate['cost'], 'hazards' => $candidate['hazards']];
                    if (!isset($options[$candidate['hex']]) || self::optionRank($option) < self::optionRank($options[$candidate['hex']])) {
                        $options[$candidate['hex']] = $option;
                    }
                }
                if ($terminal || $candidate['cost'] >= $budget) {
                    continue;
                }
                $rank = self::rank($candidate);
                if (!isset($bestState[$candidate['hex']]) || $rank < $bestState[$candidate['hex']]) {
                    $bestState[$candidate['hex']] = $rank;
                    $frontier[] = $candidate;
                }
            }
        }
        ksort($options, SORT_STRING);
        return array_values($options);
    }

    public static function canonical(array $state, string $unitId, string $destination, ?int $budget = null): ?array
    {
        foreach (self::options($state, $unitId, $budget) as $option) {
            if ($option['destination'] === $destination) {
                return $option;
            }
        }
        return null;
    }

    public static function costs(array $state, string $unitId, ?int $budget = null): array
    {
        $costs = [];
        foreach (self::options($state, $unitId, $budget) as $option) {
            $costs[$option['destination']] = $option['cost'];
        }
        return $costs;
    }

    public static function reachable(array $state, string $unitId, ?int $budget = null): array
    {
        return array_map(static fn(array $option): string => $option['destination'], self::options($state, $unitId, $budget));
    }

    public static function disengagedFrom(array $state, string $unitId, string $to): array
    {
        return self::disengagedFromPath($state, $unitId, [$to]);
    }

    public static function disengagedFromPath(array $state, string $unitId, array $path): array
    {
        $unit = $state['units'][$unitId];
        $board = $state['board'];
        $enemies = [];
        foreach ($state['units'] as $other) {
            if ($other['hex'] === null || Sides::allies($state, $unitId, $other['unitId'])) {
                continue;
            }
            if (UnitConditions::any($state, $other['unitId'], static fn(Condition $condition): bool => $condition->blocksDisengagementStrike())) {
                continue;
            }
            $from = $unit['hex'];
            foreach ($path as $to) {
                $engaged = Hex::distance($from, $other['hex']) === 1 && abs($board[$from]['elevation'] - $board[$other['hex']]['elevation']) <= 1;
                if ($engaged && Hex::distance($to, $other['hex']) !== 1) {
                    $enemies[] = $other['unitId'];
                    break;
                }
                $from = $to;
            }
        }
        sort($enemies, SORT_STRING);
        return $enemies;
    }

    private static function projectSlide(array $state, string $unitId, array $candidate, string $enteredFrom, array $occupants, int $budget): array
    {
        [$fromQ, $fromR] = Hex::parse($enteredFrom);
        [$toQ, $toR] = Hex::parse($candidate['hex']);
        $direction = [$toQ - $fromQ, $toR - $fromR];
        while ($candidate['cost'] < $budget && Terrain::type($state['board'][$candidate['hex']]) === 'ice') {
            [$q, $r] = Hex::parse($candidate['hex']);
            $next = Hex::key($q + $direction[0], $r + $direction[1]);
            if (!isset($state['board'][$next]) || isset($candidate['visited'][$next])) {
                break;
            }
            if (!Terrain::canEnter($state, $unitId, $candidate['hex'], $next, 'slip')) {
                break;
            }
            $cost = Terrain::stepCost($state, $unitId, $candidate['hex'], $next, 'slip');
            if ($candidate['cost'] + $cost > $budget) {
                break;
            }
            if (isset($occupants[$next])) {
                $enemy = Sides::enemies($state, $unitId, $occupants[$next]['unitId']);
                $hazard = [
                    'kind' => $enemy ? 'collision' : 'choice',
                    'terrain' => 'ice',
                    'hex' => $next,
                    'dice' => $enemy ? 2 : 0,
                    'forced' => true,
                    'stops' => true,
                ];
                $candidate['hazards'][] = $hazard;
                $candidate['damage'] += $hazard['dice'];
                break;
            }
            $from = $candidate['hex'];
            $candidate['hex'] = $next;
            $candidate['path'][] = $next;
            $candidate['cost'] += $cost;
            $candidate['visited'][$next] = true;
            $drop = max(0, $state['board'][$from]['elevation'] - $state['board'][$next]['elevation']);
            if ($drop > 0) {
                $fall = [
                    'kind' => 'damage', 'terrain' => 'fall', 'hex' => $next,
                    'dice' => $drop, 'forced' => true, 'stops' => false,
                ];
                $candidate['hazards'][] = $fall;
                $candidate['damage'] += $drop;
            }
            $hazards = Terrain::hazards($state, $unitId, $next, 'slip');
            $candidate['hazards'] = [...$candidate['hazards'], ...$hazards];
            $candidate['damage'] += array_sum(array_column($hazards, 'dice'));
        }
        return $candidate;
    }

    private static function rank(array $state): array
    {
        return [$state['cost'], $state['damage'], implode('|', $state['path'])];
    }

    private static function optionRank(array $option): array
    {
        return [$option['cost'], array_sum(array_column($option['hazards'], 'dice')), implode('|', $option['path'])];
    }

    private static function occupants(array $state): array
    {
        $occupants = [];
        foreach ($state['units'] as $unitId => $unit) {
            if ($unit['hex'] !== null) {
                $occupants[$unit['hex']] = ['unitId' => (string)$unitId, 'seatId' => $unit['seatId']];
            }
        }
        return $occupants;
    }
}
