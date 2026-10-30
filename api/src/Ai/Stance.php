<?php
declare(strict_types=1);

namespace BloodAndBanners\Ai;

use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\Sides;
use BloodAndBanners\Rules\Vanilla\Stats;

/**
 * Whether a personality is holding its ground or attacking right now, and the weights that go with it.
 * Derived from the state alone, so every step reaches the same answer without memory.
 */
final class Stance
{
    /** Units this close to their spawn region (or the portal) count as being at their post. */
    public const POST_RADIUS = 2;

    public static function weights(array $state, string $seatId, Personality $p): array
    {
        return self::holding($state, $seatId, $p) ? self::holdWeights($p) : self::attackWeights($p);
    }

    /** Holding: no pull toward the heroes; guarding, cover and threat avoidance keep their say. */
    public static function holdWeights(Personality $p): array
    {
        return ['advance' => 0.0] + $p->weights;
    }

    /** Attack-mode weights for this personality (what a unit away from its post plays with even while the stance holds). */
    public static function attackWeights(Personality $p): array
    {
        $weights = $p->weights;
        if (($p->stance['mode'] ?? 'attack') === 'adaptive') {
            foreach ($p->stance['aggressive'] ?? [] as $key => $factor) {
                $weights[$key] = (float)($weights[$key] ?? 0) * (float)$factor;
            }
        }
        return $weights;
    }

    public static function holding(array $state, string $seatId, Personality $p): bool
    {
        $stance = $p->stance;
        $mode = $stance['mode'] ?? 'attack';
        if ($mode === 'attack') {
            return false;
        }
        $round = (int)($state['turn']['round'] ?? 0);
        if ($round > (int)($stance['untilRound'] ?? 0)) {
            return false;
        }
        [$ours, $theirs] = self::forces($state, $seatId);
        if ($mode === 'hold') {
            $within = (int)($stance['untilHeroWithin'] ?? 0);
            foreach ($ours as $id => $unit) {
                foreach ($theirs as $enemy) {
                    if ($unit['hex'] !== null && $enemy['hex'] !== null && Hex::distance((string)$unit['hex'], (string)$enemy['hex']) <= $within) {
                        return false;
                    }
                }
            }
            return true;
        }
        if ($mode === 'holdUntilReach') {
            foreach ($ours as $id => $unit) {
                if ($unit['hex'] === null) {
                    continue;
                }
                $reach = Stats::effective($state, (string)$id, 'move') + max(1, Stats::effective($state, (string)$id, 'range'));
                foreach ($theirs as $enemy) {
                    if ($enemy['hex'] !== null && Hex::distance((string)$unit['hex'], (string)$enemy['hex']) <= $reach) {
                        return false;
                    }
                }
            }
            return true;
        }
        // adaptive: patient until the void's remaining HP share is ahead of the heroes'.
        return self::hpShare($ours) <= self::hpShare($theirs);
    }

    /**
     * The hexes a holding unit guards: its seat's spawn region, else the Void Portal, else nothing.
     * @return list<string>
     */
    public static function post(array $state): array
    {
        $spawn = [];
        $portal = [];
        foreach ($state['board'] as $key => $hex) {
            if (($hex['spawnZone'] ?? null) === 'void') {
                $spawn[] = (string)$key;
            }
            if (!empty($hex['voidPortal'])) {
                $portal[] = (string)$key;
            }
        }
        return $spawn !== [] ? $spawn : $portal;
    }

    public static function atPost(array $post, ?string $hex): bool
    {
        if ($hex === null) {
            return false;
        }
        foreach ($post as $key) {
            if (Hex::distance($key, $hex) <= self::POST_RADIUS) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0: array<string,array>, 1: array<string,array>} living, movable units of this seat's side and of the other sides */
    public static function forces(array $state, string $seatId): array
    {
        $side = self::sideOf($state, $seatId);
        $ours = [];
        $theirs = [];
        foreach ($state['units'] as $id => $unit) {
            if (!empty($unit['fixed'])) {
                continue;
            }
            if (Sides::ofUnit($state, (string)$id) === $side) {
                $ours[(string)$id] = $unit;
            } else {
                $theirs[(string)$id] = $unit;
            }
        }
        return [$ours, $theirs];
    }

    public static function sideOf(array $state, string $seatId): string
    {
        foreach ($state['sides'] ?? [] as $sideId => $side) {
            if (in_array($seatId, $side['seatIds'] ?? [], true)) {
                return (string)$sideId;
            }
        }
        return $seatId;
    }

    private static function hpShare(array $units): float
    {
        $total = 0;
        $left = 0;
        foreach ($units as $unit) {
            $hp = max(1, (int)$unit['hp']);
            $total += $hp;
            $left += $unit['hex'] === null ? 0 : max(0, $hp - (int)($unit['wounds'] ?? 0));
        }
        return $total > 0 ? $left / $total : 0.0;
    }
}
