<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\Conditions\Condition;
use BloodAndBanners\Rules\Vanilla\Conditions\ConditionRegistry;

/** The only place combat and movement read Power, Defense, Range and Move. */
final class Stats
{
    public static function effective(array $state, string $unitId, string $stat, array $context = [], ?int $base = null): int
    {
        return self::breakdown($state, $unitId, $stat, $context, $base)['value'];
    }

    /**
     * The effective value and every named source that moved it off the printed base.
     * A source with a null amount (e.g. Petrified) sets the stat to 0 outright.
     * @return array{value: int, base: int, modifiers: list<array{source: string, amount: ?int, unitId?: string}>}
     */
    public static function breakdown(array $state, string $unitId, string $stat, array $context = [], ?int $base = null): array
    {
        $base ??= (int)$state['units'][$unitId][$stat];
        if ($stat === 'defense') {
            foreach (UnitConditions::forUnit($state, $unitId) as $condition) {
                $handler = ConditionRegistry::get($condition['name']);
                if ($handler instanceof Condition && $handler->blocksDefense()) {
                    return ['value' => 0, 'base' => $base, 'modifiers' => [['source' => $condition['name'], 'amount' => null]]];
                }
            }
        }
        $modifiers = [];
        foreach (Effects::forUnit($state, $unitId) as $effect) {
            if ($effect['stat'] === $stat && (int)$effect['amount'] !== 0) {
                $modifiers[] = ['source' => (string)$effect['name'], 'amount' => (int)$effect['amount']];
            }
        }
        foreach ($state['units'] as $ownerId => $owner) {
            if ($owner['hex'] === null) {
                continue;
            }
            foreach (AbilityRegistry::abilitiesOf($owner) as $ability) {
                $delta = $ability->statDelta($state, (string)$ownerId, $unitId, $stat, $context);
                if ($delta !== 0) {
                    $modifiers[] = ['source' => $ability->name, 'amount' => $delta] + ((string)$ownerId === $unitId ? [] : ['unitId' => (string)$ownerId]);
                }
            }
        }
        foreach (UnitConditions::forUnit($state, $unitId) as $condition) {
            $delta = ConditionRegistry::get($condition['name'])?->statDelta($state, $unitId, $stat, $context) ?? 0;
            if ($delta !== 0) {
                $modifiers[] = ['source' => (string)$condition['name'], 'amount' => $delta];
            }
        }
        foreach (Terrain::statSources($state, $unitId, $stat, $context) as $source) {
            $modifiers[] = $source;
        }
        foreach (Crystals::statSources($state, $unitId, $stat, $context) as $source) {
            $modifiers[] = $source;
        }
        $value = $base + array_sum(array_column($modifiers, 'amount'));
        return ['value' => max(0, $value), 'base' => $base, 'modifiers' => $modifiers];
    }
}
