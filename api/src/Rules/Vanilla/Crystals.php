<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\Vanilla\Conditions\Condition;

/**
 * Crystal landmarks. A unit that finishes its own move next to one, unengaged and with no enemy beside the crystal,
 * claims it for its player; the owner's units get the crystal's combat bonus until someone else claims it.
 */
final class Crystals
{
    public const KINDS = ['fire-crystal' => 'Fire Crystal', 'forest-crystal' => 'Forest Crystal', 'ice-crystal' => 'Ice Crystal'];
    private const WATER_ABILITIES = ['Water Twister', 'Aquatic Surge'];
    private const FIRE_ABILITIES = ['Fireball', 'Flame Strike', 'Dragon Breath', 'Inferno', 'Lava Burst', "Dragon's Breath"];

    /** Claims every crystal next to the unit that it may claim. Call only when the unit's own move has ended. */
    public static function claim(array $state, string $unitId, array &$events): array
    {
        $unit = $state['units'][$unitId];
        if ($unit['hex'] === null
            || ($state['sides'][Sides::ofUnit($state, $unitId)]['kind'] ?? null) === 'void'
            || self::engaged($state, $unitId)) {
            return $state;
        }
        $seatId = (string)$unit['seatId'];
        foreach (Hex::neighbors($unit['hex'], $state['board']) as $hex) {
            $kind = $state['board'][$hex]['object'] ?? null;
            if ($kind === null || !isset(self::KINDS[$kind]) || self::guarded($state, $unitId, $hex)) {
                continue;
            }
            $previous = $state['crystals'][$hex] ?? null;
            if ($previous === $seatId) {
                continue;
            }
            $state['crystals'][$hex] = $seatId;
            $events[] = MatchEngine::event('crystalClaimed', [$hex], [
                'seatId' => $seatId, 'unitId' => $unitId, 'hex' => $hex, 'kind' => $kind, 'previousSeatId' => $previous,
            ]);
        }
        return $state;
    }

    /**
     * One named source per crystal the unit's player owns whose bonus fits this attack, so owning two of a kind stacks.
     * @return list<array{source: string, amount: int}>
     */
    public static function statSources(array $state, string $unitId, string $stat, array $context): array
    {
        $seatId = $state['units'][$unitId]['seatId'] ?? null;
        $sources = [];
        foreach ($state['crystals'] ?? [] as $hex => $owner) {
            $kind = $state['board'][(string)$hex]['object'] ?? null;
            if ($owner !== $seatId || $kind === null || !isset(self::KINDS[$kind])) {
                continue;
            }
            $amount = self::bonus($state, $kind, $unitId, $stat, $context);
            if ($amount !== 0) {
                $sources[] = ['source' => self::KINDS[$kind], 'amount' => $amount];
            }
        }
        return $sources;
    }

    private static function bonus(array $state, string $kind, string $unitId, string $stat, array $context): int
    {
        $attacking = $stat === 'power' && ($context['attackerId'] ?? null) === $unitId;
        $defending = $stat === 'defense' && ($context['defenderId'] ?? null) === $unitId;
        $foeId = $attacking ? ($context['defenderId'] ?? null) : ($defending ? ($context['attackerId'] ?? null) : null);
        if ($foeId === null || !isset($state['units'][$foeId])) {
            return 0;
        }
        $foeBiome = $state['units'][$foeId]['biome'] ?? null;
        $ability = $context['ability'] ?? null;
        $water = $foeBiome === 'River' || in_array($ability, self::WATER_ABILITIES, true);
        $fire = $foeBiome === 'Lava' || ($context['damageType'] ?? null) === 'fire' || in_array($ability, self::FIRE_ABILITIES, true);
        return match (true) {
            $kind === 'fire-crystal' && $attacking && $foeBiome === 'Forest' => 2,
            $kind === 'forest-crystal' && $defending && $water => 4,
            $kind === 'ice-crystal' && $attacking && $foeBiome === 'Lava' => 1,
            $kind === 'ice-crystal' && $defending && $fire => 2,
            default => 0,
        };
    }

    /** An enemy stands next to the unit within one level of height and could strike it for leaving, as for disengagement strikes. */
    private static function engaged(array $state, string $unitId): bool
    {
        $here = $state['units'][$unitId]['hex'];
        foreach ($state['units'] as $otherId => $other) {
            if ($other['hex'] !== null
                && Sides::enemies($state, $unitId, (string)$otherId)
                && !UnitConditions::any($state, (string)$otherId, static fn(Condition $condition): bool => $condition->blocksDisengagementStrike())
                && Hex::distance($here, $other['hex']) === 1
                && abs($state['board'][$here]['elevation'] - $state['board'][$other['hex']]['elevation']) <= 1) {
                return true;
            }
        }
        return false;
    }

    /** An enemy of the unit stands next to the crystal. */
    private static function guarded(array $state, string $unitId, string $crystal): bool
    {
        foreach ($state['units'] as $otherId => $other) {
            if ($other['hex'] !== null && Sides::enemies($state, $unitId, (string)$otherId) && Hex::distance($crystal, $other['hex']) === 1) {
                return true;
            }
        }
        return false;
    }
}
