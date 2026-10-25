<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

/**
 * Potions on the map. A player unit that comes to rest on one drinks it at once and it is gone. The Void Lord
 * places them as rewards his monsters guard, so his units never drink.
 */
final class Potions
{
    public const KINDS = ['power-potion' => 'Power Potion', 'speed-potion' => 'Speed Potion', 'health-potion' => 'Health Potion'];
    private const TURNS = 3;
    private const BONUS = 2;

    /** Every player unit now standing on a potion drinks it. Call once the board has settled: nothing pending. */
    public static function drinkAll(array $state, array &$events): array
    {
        if ($state['phase'] !== 'active' || $state['pending'] !== null) {
            return $state;
        }
        $unitIds = array_map('strval', array_keys($state['units']));
        sort($unitIds, SORT_STRING);
        foreach ($unitIds as $unitId) {
            $hex = $state['units'][$unitId]['hex'];
            $kind = $hex === null ? null : ($state['board'][$hex]['object'] ?? null);
            if ($kind === null || !isset(self::KINDS[$kind])
                || ($state['sides'][Sides::ofUnit($state, $unitId)]['kind'] ?? null) === 'void') {
                continue;
            }
            $state = self::drink($state, $unitId, $hex, $kind, $events);
        }
        return $state;
    }

    private static function drink(array $state, string $unitId, string $hex, string $kind, array &$events): array
    {
        $seatId = (string)$state['units'][$unitId]['seatId'];
        $state['board'][$hex]['object'] = null;
        $events[] = MatchEngine::event('potionDrunk', [$hex], ['unitId' => $unitId, 'seatId' => $seatId, 'hex' => $hex, 'kind' => $kind]);
        if ($kind === 'health-potion') {
            $state['units'][$unitId]['wounds'] = 0;
            return $state;
        }
        $stat = $kind === 'power-potion' ? 'power' : 'move';
        return Effects::add($state, self::KINDS[$kind], $unitId, $unitId, $stat, self::BONUS, $seatId, Effects::UNTIL_END_OF_TURN, $events, self::TURNS);
    }
}
