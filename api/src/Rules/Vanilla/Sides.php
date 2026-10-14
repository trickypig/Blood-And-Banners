<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

/** Allegiance. A seat commands units; a side groups the seats whose units fight together. */
final class Sides
{
    /** One side per seat, as in a duel. */
    public static function perSeat(array $seatIds): array
    {
        $sides = [];
        foreach ($seatIds as $seatId) {
            $sides[(string)$seatId] = ['kind' => 'player', 'seatIds' => [(string)$seatId]];
        }
        return $sides;
    }

    public static function ofUnit(array $state, string $unitId): string
    {
        $unit = $state['units'][$unitId];
        return (string)($unit['side'] ?? $unit['seatId']);
    }

    public static function ofSeat(array $state, string $seatId): string
    {
        foreach ($state['sides'] ?? [] as $sideId => $side) {
            if (in_array($seatId, $side['seatIds'], true)) {
                return (string)$sideId;
            }
        }
        return $seatId;
    }

    public static function enemies(array $state, string $unitId, string $otherId): bool
    {
        return self::ofUnit($state, $unitId) !== self::ofUnit($state, $otherId);
    }

    public static function allies(array $state, string $unitId, string $otherId): bool
    {
        return !self::enemies($state, $unitId, $otherId);
    }

    /** Whether any unit of $sideId is still on the battlefield. */
    public static function anyAlive(array $state, string $sideId): bool
    {
        foreach ($state['units'] as $unitId => $unit) {
            if ($unit['hex'] !== null && self::ofUnit($state, (string)$unitId) === $sideId) {
                return true;
            }
        }
        return false;
    }
}
