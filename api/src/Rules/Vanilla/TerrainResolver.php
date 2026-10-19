<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\CustomDice;
use BloodAndBanners\Rules\RandomSource;

final class TerrainResolver
{
    public static function enter(array $state, string $unitId, string $from, string $to, string $movementKind, ?array $direction, RandomSource $random, array &$events): array
    {
        $state['units'][$unitId]['hex'] = $to;
        $drop = $state['board'][$from]['elevation'] - $state['board'][$to]['elevation'];
        if ($movementKind === 'voluntary' && $drop >= 2 && !Terrain::hasTrait($state, $unitId, 'Flying')) {
            $roll = (new CustomDice())->roll($drop, $random);
            $events[] = MatchEngine::event('terrainDamage', [$to], [
                'unitId' => $unitId, 'terrain' => 'fall', 'cause' => 'fall', 'dice' => $drop,
                'faces' => $roll['faces'], 'wounds' => $roll['swords'], 'hex' => $to,
            ]);
            $state = Wounds::apply($state, $unitId, $roll['swords'], $events, $random);
            if ($state['phase'] !== 'active' || $state['units'][$unitId]['hex'] === null) {
                return ['state' => $state, 'stop' => true];
            }
        }
        $type = Terrain::type($state['board'][$to]);
        if ($type !== 'ice' && (UnitConditions::has($state, $unitId, 'Unsteady') || UnitConditions::has($state, $unitId, 'Slipping'))) {
            $state = self::clearIce($state, $unitId, $events, true);
        }
        if ($type === null) {
            return ['state' => $state, 'stop' => false];
        }
        $paint = $state['board'][$to]['terrain'];
        $events[] = MatchEngine::event('terrainEntered', [$to], [
            'unitId' => $unitId, 'terrain' => $paint, 'hex' => $to, 'movementKind' => $movementKind,
        ]);
        if (Terrain::ignored($state, $unitId, $type, $movementKind)) {
            $ability = match (true) {
                $type === 'lava' && Terrain::hasTrait($state, $unitId, 'Lava Walker') => 'Lava Walker',
                $type === 'water' && Terrain::hasTrait($state, $unitId, 'Water Bound') => 'Water Bound',
                default => 'Flying',
            };
            $events[] = MatchEngine::event('terrainIgnored', [$to], [
                'unitId' => $unitId, 'terrain' => $paint, 'ability' => $ability, 'hex' => $to,
            ]);
            return ['state' => $state, 'stop' => false];
        }

        if ($type === 'ice') {
            if ($movementKind === 'teleport') {
                $state = UnitConditions::clear($state, $unitId, 'Slipping', $events);
            }
            $state = UnitConditions::apply($state, 'Unsteady', $unitId, null, ['kind' => 'whileOnTerrain'], $events);
            if ($movementKind !== 'teleport' && $direction !== null) {
                $locked = UnitConditions::named($state, $unitId, 'Slipping')['data']['direction'] ?? $direction;
                $state = UnitConditions::apply(
                    $state,
                    'Slipping',
                    $unitId,
                    null,
                    ['kind' => 'untilCleared'],
                    $events,
                    ['direction' => array_values($locked)]
                );
            }
            return ['state' => $state, 'stop' => false];
        }

        $dice = $type === 'lava' ? 5 : ($type === 'water' && Terrain::hasTrait($state, $unitId, 'Lava Walker') ? 1 : 0);
        if ($dice > 0) {
            $roll = (new CustomDice())->roll($dice, $random);
            $events[] = MatchEngine::event('terrainDamage', [$to], [
                'unitId' => $unitId, 'terrain' => $paint, 'cause' => 'entry', 'dice' => $dice,
                'faces' => $roll['faces'], 'wounds' => $roll['swords'], 'hex' => $to,
            ]);
            $state = Wounds::apply($state, $unitId, $roll['swords'], $events, $random);
        }
        if ($state['phase'] !== 'active' || $state['units'][$unitId]['hex'] === null) {
            return ['state' => $state, 'stop' => true];
        }
        if ($type === 'poison') {
            $state = UnitConditions::apply($state, 'Poisoned', $unitId, null, ['kind' => 'untilCleared'], $events);
        }
        $stop = $movementKind === 'voluntary' && in_array($type, ['water', 'poison'], true);
        if ($stop) {
            $events[] = MatchEngine::event('movementStopped', [$to], ['unitId' => $unitId, 'hex' => $to, 'reason' => $type]);
        }
        return ['state' => $state, 'stop' => $stop];
    }

    public static function clearIce(array $state, string $unitId, array &$events, bool $leftIce): array
    {
        $state = UnitConditions::clear($state, $unitId, 'Slipping', $events);
        if ($leftIce) {
            $state = UnitConditions::clear($state, $unitId, 'Unsteady', $events);
        }
        return $state;
    }

    public static function atTurnEnd(array $state, string $seatId, RandomSource $random, array &$events): array
    {
        $ids = array_map('strval', array_keys($state['units']));
        sort($ids, SORT_STRING);
        foreach ($ids as $unitId) {
            $unit = $state['units'][$unitId];
            if ($unit['seatId'] !== $seatId || $unit['hex'] === null || !UnitConditions::has($state, $unitId, 'Poisoned')) {
                continue;
            }
            $state = Poison::roll($state, $unitId, $random, $events);
            if ($state['phase'] !== 'active') {
                return $state;
            }
        }
        foreach ($ids as $unitId) {
            $unit = $state['units'][$unitId];
            if ($unit['seatId'] !== $seatId || $unit['hex'] === null || Terrain::type($state['board'][$unit['hex']]) !== 'lava') {
                continue;
            }
            if (Terrain::hasTrait($state, $unitId, 'Lava Walker')) {
                $events[] = MatchEngine::event('terrainIgnored', [$unit['hex']], [
                    'unitId' => $unitId, 'terrain' => 'lava', 'ability' => 'Lava Walker', 'hex' => $unit['hex'],
                ]);
                continue;
            }
            $roll = (new CustomDice())->roll(5, $random);
            $events[] = MatchEngine::event('terrainDamage', [$unit['hex']], [
                'unitId' => $unitId, 'terrain' => 'lava', 'cause' => 'turnEnd', 'dice' => 5,
                'faces' => $roll['faces'], 'wounds' => $roll['swords'], 'hex' => $unit['hex'],
            ]);
            $state = Wounds::apply($state, $unitId, $roll['swords'], $events, $random);
            if ($state['phase'] !== 'active') {
                return $state;
            }
        }
        return $state;
    }
}
