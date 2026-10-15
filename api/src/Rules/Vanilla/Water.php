<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

final class Water
{
    public static function contains(array $state, string $hex): bool
    {
        return in_array($state['board'][$hex]['terrain'] ?? '', ['open-water', 'shallow-water'], true);
    }

    public static function nearby(array $state, string $hex): array
    {
        $hexes = array_values(array_filter([$hex, ...Hex::neighbors($hex, $state['board'])], static fn(string $key): bool => self::contains($state, $key)));
        sort($hexes, SORT_STRING);
        return $hexes;
    }
}
