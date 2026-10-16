<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\Conditions\Condition;

/** The only place combat and movement read Power, Defense, Range and Move. */
final class Stats
{
    public static function effective(array $state, string $unitId, string $stat, array $context = [], ?int $base = null): int
    {
        if ($stat === 'defense' && UnitConditions::any($state, $unitId, static fn(Condition $condition): bool => $condition->blocksDefense())) {
            return 0;
        }
        $value = ($base ?? (int)$state['units'][$unitId][$stat]) + Effects::total($state, $unitId, $stat);
        foreach ($state['units'] as $ownerId => $owner) {
            if ($owner['hex'] === null) {
                continue;
            }
            foreach (AbilityRegistry::abilitiesOf($owner) as $ability) {
                $value += $ability->statDelta($state, (string)$ownerId, $unitId, $stat, $context);
            }
        }
        $value += UnitConditions::statDelta($state, $unitId, $stat, $context);
        $value += Terrain::statDelta($state, $unitId, $stat, $context);
        return max(0, $value);
    }
}
