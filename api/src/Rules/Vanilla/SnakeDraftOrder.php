<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

final class SnakeDraftOrder
{
    public static function seatAt(array $seatIds, int $pickIndex): string
    {
        $forward = array_values($seatIds);
        $cycle = array_merge($forward, array_reverse($forward));
        return $cycle[$pickIndex % count($cycle)];
    }
}
