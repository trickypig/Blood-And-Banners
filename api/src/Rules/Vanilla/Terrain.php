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

    public static function stepCost(array $state, string $unitId, string $from, string $to, string $movementKind): int
    {
        return self::type($state['board'][$from]) === 'water' && !self::ignored($state, $unitId, 'water', $movementKind) ? 2 : 1;
    }

    public static function canEnter(array $state, string $unitId, string $from, string $to, string $movementKind): bool
    {
        $destination = $state['board'][$to] ?? null;
        if ($destination === null || $destination['sightBlocker']) {
            return false;
        }
        $source = $state['board'][$from];
        $rise = $destination['elevation'] - $source['elevation'];
        if (self::hasTrait($state, $unitId, 'Flying')) {
            return $rise <= 2;
        }
        if (self::type($destination) === 'ice' && $rise > 0) {
            return false;
        }
        if ($movementKind === 'slip' && $rise < 0) {
            return true;
        }
        $leavingWater = self::type($source) === 'water' && !self::ignored($state, $unitId, 'water', $movementKind);
        $effectiveDestination = $destination['elevation'] + ($leavingWater && $rise >= 0 ? 1 : 0);
        return abs($effectiveDestination - $source['elevation']) <= 1;
    }

    public static function canEnd(array $state, string $unitId, string $hex, string $movementKind): bool
    {
        return !(self::type($state['board'][$hex]) === 'lava' && self::hasTrait($state, $unitId, 'Flying') && $movementKind === 'voluntary');
    }

    public static function stopsVoluntary(array $state, string $unitId, string $hex): bool
    {
        $type = self::type($state['board'][$hex]);
        return in_array($type, ['water', 'poison'], true) && !self::ignored($state, $unitId, (string)$type, 'voluntary');
    }

    public static function hazards(array $state, string $unitId, string $hex, string $movementKind): array
    {
        $type = self::type($state['board'][$hex]);
        if ($type === null || self::ignored($state, $unitId, $type, $movementKind)) {
            return [];
        }
        return match ($type) {
            'water' => [[
                'kind' => self::hasTrait($state, $unitId, 'Lava Walker') ? 'damage' : 'terrain',
                'terrain' => 'water', 'hex' => $hex,
                'dice' => self::hasTrait($state, $unitId, 'Lava Walker') ? 1 : 0,
                'forced' => false, 'stops' => $movementKind === 'voluntary',
            ]],
            'ice' => [[
                'kind' => 'slip', 'terrain' => 'ice', 'hex' => $hex,
                'dice' => 0, 'forced' => true, 'stops' => false,
            ]],
            'poison' => [[
                'kind' => 'condition', 'terrain' => 'poison', 'hex' => $hex,
                'dice' => 0, 'forced' => false, 'stops' => $movementKind === 'voluntary',
            ]],
            'lava' => [[
                'kind' => 'damage', 'terrain' => 'lava', 'hex' => $hex,
                'dice' => 5, 'forced' => false, 'stops' => false,
            ]],
        };
    }
}
