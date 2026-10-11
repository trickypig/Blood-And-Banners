<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use InvalidArgumentException;

final class Hex
{
    public const DIRECTIONS = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]];

    public static function key(int $q, int $r): string
    {
        return "{$q},{$r}";
    }

    public static function parse(string $key): array
    {
        if (preg_match('/^(-?\d+),(-?\d+)$/', $key, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid axial hex key {$key}.");
        }
        return [(int)$matches[1], (int)$matches[2]];
    }

    public static function distance(string $from, string $to): int
    {
        [$fromQ, $fromR] = self::parse($from);
        [$toQ, $toR] = self::parse($to);
        $deltaQ = $toQ - $fromQ;
        $deltaR = $toR - $fromR;
        return intdiv(abs($deltaQ) + abs($deltaR) + abs($deltaQ + $deltaR), 2);
    }

    public static function neighbors(string $key, array $board): array
    {
        [$q, $r] = self::parse($key);
        $neighbors = [];
        foreach (self::DIRECTIONS as [$deltaQ, $deltaR]) {
            $neighbor = self::key($q + $deltaQ, $r + $deltaR);
            if (isset($board[$neighbor])) {
                $neighbors[] = $neighbor;
            }
        }
        return $neighbors;
    }
}
