<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

/**
 * Crystal landmarks. A unit that finishes its own move next to one, unengaged and with no enemy beside the crystal,
 * claims it for its player; the owner's units get the crystal's combat bonus until someone else claims it.
 */
final class Crystals
{
    public const KINDS = ['fire-crystal' => 'Fire Crystal', 'forest-crystal' => 'Forest Crystal', 'ice-crystal' => 'Ice Crystal'];

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

    /** An enemy stands next to the unit within one level of height. */
    private static function engaged(array $state, string $unitId): bool
    {
        $here = $state['units'][$unitId]['hex'];
        foreach ($state['units'] as $otherId => $other) {
            if ($other['hex'] !== null
                && Sides::enemies($state, $unitId, (string)$otherId)
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
