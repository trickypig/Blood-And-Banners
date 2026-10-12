<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;

final class Terrain
{
    private const TYPES = [
        'open-water' => 'water',
        'ice' => 'ice',
        'poison-pits' => 'poison',
        'lava' => 'lava',
    ];

    public static function type(array $hex): ?string
    {
        return self::TYPES[(string)($hex['terrain'] ?? '')] ?? null;
    }

    public static function hasTrait(array $state, string $unitId, string $ability): bool
    {
        return isset($state['units'][$unitId])
            && AbilityRegistry::find($state['units'][$unitId], $ability) !== null;
    }

    public static function statDelta(array $state, string $unitId, string $stat, array $context = []): int
    {
        $unit = $state['units'][$unitId];
        $hex = $unit['hex'] === null ? null : ($state['board'][$unit['hex']] ?? null);
        $delta = 0;
        if ($stat === 'power' && $hex !== null && self::type($hex) === 'water' && !self::ignored($state, $unitId, 'water', 'standing')) {
            $delta--;
        }
        if ($stat === 'defense' && isset($context['damageType'])) {
            foreach (AbilityRegistry::abilitiesOf($unit) as $ability) {
                $delta += $ability->defenseDeltaForDamageType((string)$context['damageType']);
            }
        }
        return $delta;
    }

    public static function ignored(array $state, string $unitId, string $terrain, string $movementKind): bool
    {
        foreach (AbilityRegistry::abilitiesOf($state['units'][$unitId]) as $ability) {
            if ($ability->ignoresTerrain($terrain, $movementKind)) {
                return true;
            }
        }
        return false;
    }
}
