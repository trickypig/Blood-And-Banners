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
        $type = Terrain::type($state['board'][$to]);
        if ($type === null) {
            return ['state' => $state, 'stop' => false];
        }
        $paint = $state['board'][$to]['terrain'];
        $events[] = MatchEngine::event('terrainEntered', [$to], [
            'unitId' => $unitId, 'terrain' => $paint, 'hex' => $to, 'movementKind' => $movementKind,
        ]);
        if (Terrain::ignored($state, $unitId, $type, $movementKind)) {
            $ability = Terrain::hasTrait($state, $unitId, 'Lava Walker') && $type === 'lava' ? 'Lava Walker' : 'Flying';
            $events[] = MatchEngine::event('terrainIgnored', [$to], [
                'unitId' => $unitId, 'terrain' => $paint, 'ability' => $ability, 'hex' => $to,
            ]);
            return ['state' => $state, 'stop' => false];
        }

        $dice = $type === 'lava' ? 5 : ($type === 'water' && Terrain::hasTrait($state, $unitId, 'Lava Walker') ? 1 : 0);
        if ($dice > 0) {
            $roll = (new CustomDice())->roll($dice, $random);
            $events[] = MatchEngine::event('terrainDamage', [$to], [
                'unitId' => $unitId, 'terrain' => $paint, 'cause' => 'entry', 'dice' => $dice,
                'faces' => $roll['faces'], 'wounds' => $roll['swords'], 'hex' => $to,
            ]);
            $state = Wounds::apply($state, $unitId, $roll['swords'], $events);
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
}
