<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

final class Targeting
{
    /** Living enemies within $range that $unitId can see. */
    public static function enemiesInSight(array $state, string $unitId, int $range): array
    {
        $self = $state['units'][$unitId];
        return array_values(array_filter(
            self::unitsWithin($state, $unitId, $range, false),
            static fn(string $id): bool => $state['units'][$id]['seatId'] !== $self['seatId']
                && Combat::visible($state, $self['hex'], $state['units'][$id]['hex'])
        ));
    }

    public static function alliesWithin(array $state, string $unitId, int $range, bool $includeSelf): array
    {
        $seatId = $state['units'][$unitId]['seatId'];
        return array_values(array_filter(
            self::unitsWithin($state, $unitId, $range, $includeSelf),
            static fn(string $id): bool => $state['units'][$id]['seatId'] === $seatId
        ));
    }

    /** Living units within $range hexes, sorted by id. */
    public static function unitsWithin(array $state, string $unitId, int $range, bool $includeSelf): array
    {
        $origin = $state['units'][$unitId]['hex'];
        $found = [];
        foreach ($state['units'] as $id => $unit) {
            $id = (string)$id;
            if ($unit['hex'] === null || (!$includeSelf && $id === $unitId)) {
                continue;
            }
            if (Hex::distance($origin, $unit['hex']) <= $range) {
                $found[] = $id;
            }
        }
        sort($found, SORT_STRING);
        return $found;
    }
}
