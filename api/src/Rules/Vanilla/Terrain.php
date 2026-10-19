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
        return array_sum(array_column(self::statSources($state, $unitId, $stat, $context), 'amount'));
    }

    /** @return list<array{source: string, amount: int}> */
    public static function statSources(array $state, string $unitId, string $stat, array $context = []): array
    {
        $unit = $state['units'][$unitId];
        $hex = $unit['hex'] === null ? null : ($state['board'][$unit['hex']] ?? null);
        $sources = [];
        if ($stat === 'power' && $hex !== null && self::type($hex) === 'water' && !self::ignored($state, $unitId, 'water', 'standing')) {
            $sources[] = ['source' => 'Standing in water', 'amount' => -1];
        }
        if ($stat === 'defense' && isset($context['damageType'])) {
            foreach (AbilityRegistry::abilitiesOf($unit) as $ability) {
                $delta = $ability->defenseDeltaForDamageType((string)$context['damageType']);
                if ($delta !== 0) {
                    $sources[] = ['source' => $ability->name, 'amount' => $delta];
                }
            }
        }
        return $sources;
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
        if ($movementKind === 'voluntary' && !self::allowsVoluntaryHex($state, $state['units'][$unitId], $to)) {
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
        if (in_array($movementKind, ['voluntary', 'slip'], true) && $rise < 0) {
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

    public static function allowsVoluntaryHex(array $state, array $unit, string $hex): bool
    {
        foreach (AbilityRegistry::abilitiesOf($unit) as $ability) {
            if (!$ability->allowsVoluntaryHex($state, $unit, $hex)) {
                return false;
            }
        }
        return true;
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
                'kind' => $movementKind === 'teleport' ? 'condition' : 'slip', 'terrain' => 'ice', 'hex' => $hex,
                'dice' => 0, 'forced' => $movementKind !== 'teleport', 'stops' => false,
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
