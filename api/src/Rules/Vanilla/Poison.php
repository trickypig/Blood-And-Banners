<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\CustomDice;
use BloodAndBanners\Rules\RandomSource;

/** Poison damage and recovery are identical whether rolled immediately or at turn end. */
final class Poison
{
    public static function apply(array $state, string $unitId, string $sourceUnitId, RandomSource $random, array &$events, bool $immediate = false): array
    {
        if ($state['phase'] !== 'active' || $state['units'][$unitId]['hex'] === null) {
            return $state;
        }
        $alreadyPoisoned = UnitConditions::has($state, $unitId, 'Poisoned');
        if (!$alreadyPoisoned) {
            $state = UnitConditions::apply($state, 'Poisoned', $unitId, $sourceUnitId, ['kind' => 'untilCleared'], $events);
        }
        return $immediate && !$alreadyPoisoned ? self::roll($state, $unitId, $random, $events) : $state;
    }

    public static function roll(array $state, string $unitId, RandomSource $random, array &$events): array
    {
        $unit = $state['units'][$unitId];
        if ($state['phase'] !== 'active' || $unit['hex'] === null || !UnitConditions::has($state, $unitId, 'Poisoned')) {
            return $state;
        }
        $roll = (new CustomDice())->roll(1, $random);
        $onPoison = Terrain::type($state['board'][$unit['hex']]) === 'poison';
        $cleared = in_array('shield', $roll['faces'], true) && !$onPoison;
        $events[] = MatchEngine::event('poisonRolled', [$unit['hex']], [
            'unitId' => $unitId, 'hex' => $unit['hex'], 'faces' => $roll['faces'],
            'wounds' => $roll['swords'], 'cleared' => $cleared,
        ]);
        $state = Wounds::apply($state, $unitId, $roll['swords'], $events, $random);
        return $cleared && $state['phase'] === 'active' ? UnitConditions::clear($state, $unitId, 'Poisoned', $events) : $state;
    }
}
