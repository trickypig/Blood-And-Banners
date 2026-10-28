<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

/**
 * Potions on the map and what they do when drunk. Nothing drinks automatically: a unit that ends its own move on one
 * chooses what to do (see Items). The Void Lord places them as rewards his monsters guard, so his units never take them.
 */
final class Potions
{
    public const KINDS = ['power-potion' => 'Power Potion', 'speed-potion' => 'Speed Potion', 'health-potion' => 'Health Potion'];
    private const TURNS = 3;
    private const BONUS = 2;

    /** Applies a potion's effect to the unit. The caller has already taken the potion off the map or out of a pack. */
    public static function applyEffect(array $state, string $unitId, string $hex, string $kind, array &$events): array
    {
        $seatId = (string)$state['units'][$unitId]['seatId'];
        $events[] = MatchEngine::event('potionDrunk', [$hex], ['unitId' => $unitId, 'seatId' => $seatId, 'hex' => $hex, 'kind' => $kind]);
        if ($kind === 'health-potion') {
            $state['units'][$unitId]['wounds'] = 0;
            return $state;
        }
        $stat = $kind === 'power-potion' ? 'power' : 'move';
        return Effects::add($state, self::KINDS[$kind], $unitId, $unitId, $stat, self::BONUS, $seatId, Effects::UNTIL_END_OF_TURN, $events, self::TURNS);
    }
}
