<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\Vanilla\Abilities\Ability;
use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\Modes\Modes;

final class Wounds
{
    public static function apply(array $state, string $unitId, int $wounds, array &$events): array
    {
        $unit = $state['units'][$unitId];
        if ($wounds <= 0 || $unit['hex'] === null || $state['phase'] !== 'active') {
            return $state;
        }
        if (AbilityRegistry::any($unit, static fn(Ability $ability): bool => $ability->locksSpellsWhenWounded())) {
            // Keep locks that still lie ahead (a wound during a locked turn must not unlock it).
            $current = $state['turn']['number'];
            $locks = array_values(array_filter(
                $unit['flags']['spellsLockedOnTurns'] ?? [],
                static fn(int $turn): bool => $turn >= $current
            ));
            $locks[] = Turn::nextTurnNumberOf($state, $unit['seatId']);
            $state['units'][$unitId]['flags']['spellsLockedOnTurns'] = array_values(array_unique($locks));
        }
        $total = min($unit['hp'], $unit['wounds'] + $wounds);
        if ($total >= $unit['hp']
            && empty($unit['flags']['lastHornSpent'])
            && AbilityRegistry::any($unit, static fn(Ability $ability): bool => $ability->survivesDefeatOnce())) {
            $state['units'][$unitId]['wounds'] = $unit['hp'] - 1;
            $state['units'][$unitId]['flags']['lastHornSpent'] = true;
            $events[] = MatchEngine::event('survivedDefeat', [$unit['hex']], ['unitId' => $unitId, 'ability' => 'One Last Horn']);
            return $state;
        }
        $state['units'][$unitId]['wounds'] = $total;
        if ($total < $unit['hp']) {
            return $state;
        }
        $events[] = MatchEngine::event('unitDefeated', [$unit['hex']], ['unitId' => $unitId]);
        $state['units'][$unitId]['hex'] = null;
        $state = UnitConditions::clearUnit($state, $unitId);
        if ($state['turn']['activeUnitId'] === $unitId) {
            $state['turn']['activeUnitId'] = null;
        }
        $result = Modes::ofState($state)->victory($state);
        if ($result !== null) {
            $state['phase'] = 'completed';
            $state['result'] = $result;
            $state['turn']['activeUnitId'] = null;
            $state['pending'] = null;
            $events[] = MatchEngine::event('matchCompleted', [], $result);
            return $state;
        }
        if (!self::anyAlive($state, $unit['seatId'])) {
            // A wiped-out seat never takes another turn, so its effects could never expire on their own.
            $state = Effects::expire($state, $unit['seatId'], Effects::UNTIL_END_OF_TURN, $events);
            $state = Effects::expire($state, $unit['seatId'], Effects::UNTIL_NEXT_TURN, $events);
        }
        return $state;
    }

    public static function anyAlive(array $state, string $seatId): bool
    {
        foreach ($state['units'] as $unit) {
            if ($unit['seatId'] === $seatId && $unit['hex'] !== null) {
                return true;
            }
        }
        return false;
    }
}
