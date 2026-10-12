<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\Vanilla\Abilities\Ability;
use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;

final class Wounds
{
    public static function apply(array $state, string $unitId, int $wounds, array &$events): array
    {
        $unit = $state['units'][$unitId];
        if ($wounds <= 0 || $unit['hex'] === null || $state['phase'] !== 'active') {
            return $state;
        }
        if (AbilityRegistry::any($unit, static fn(Ability $ability): bool => $ability->locksSpellsWhenWounded())) {
            $state['units'][$unitId]['flags']['spellsLockedOnTurn'] = Turn::nextTurnNumberOf($state, $unit['seatId']);
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
        if ($state['turn']['activeUnitId'] === $unitId) {
            $state['turn']['activeUnitId'] = null;
        }
        foreach ($state['seats'] as $seatId) {
            if (!self::anyAlive($state, $seatId)) {
                $winner = MatchEngine::otherSeat($state, $seatId);
                $state['phase'] = 'completed';
                $state['result'] = ['reason' => 'annihilation', 'winningSeatId' => $winner];
                $state['turn']['activeUnitId'] = null;
                $state['pending'] = null;
                $events[] = MatchEngine::event('matchCompleted', [], ['winningSeatId' => $winner, 'reason' => 'annihilation']);
                break;
            }
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
