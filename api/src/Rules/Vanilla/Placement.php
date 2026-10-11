<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Game\GameServiceException;

final class Placement
{
    public static function place(array $board, string $homeKey, string $opponentHomeKey, array $unitIds, array $occupied): array
    {
        $occupied[$homeKey] = true;
        $occupied[$opponentHomeKey] = true;
        $placed = [];
        foreach (array_values($unitIds) as $index => $unitId) {
            $anchors = $index < 4 ? [$homeKey] : array_values($placed);
            $candidates = [];
            foreach ($anchors as $anchor) {
                foreach (Hex::neighbors($anchor, $board) as $key) {
                    if (!$board[$key]['sightBlocker'] && !isset($occupied[$key])) {
                        $candidates[$key] = $key;
                    }
                }
            }
            if ($candidates === []) {
                throw self::tooSmall();
            }
            $candidates = array_values($candidates);
            usort(
                $candidates,
                static fn(string $a, string $b): int =>
                    self::rank($a, $index, $homeKey, $opponentHomeKey) <=> self::rank($b, $index, $homeKey, $opponentHomeKey)
            );
            $placed[(string)$unitId] = $candidates[0];
            $occupied[$candidates[0]] = true;
        }
        return $placed;
    }

    public static function assertCapacity(array $board, int $unitsPerSide): void
    {
        $homes = Board::homes($board);
        if (!isset($homes[1], $homes[2])) {
            throw self::tooSmall();
        }
        $probe = array_map(static fn(int $index): string => "probe-{$index}", range(1, $unitsPerSide));
        $first = self::place($board, $homes[1], $homes[2], $probe, []);
        self::place($board, $homes[2], $homes[1], $probe, array_fill_keys(array_values($first), true));
    }

    private static function rank(string $key, int $index, string $home, string $opponent): array
    {
        [$q, $r] = Hex::parse($key);
        return $index < 4
            ? [Hex::distance($key, $opponent), $q, $r]
            : [Hex::distance($key, $home), Hex::distance($key, $opponent), $q, $r];
    }

    private static function tooSmall(): GameServiceException
    {
        return new GameServiceException('scenarioTooSmall', 'This battlefield cannot fit both armies beside their home portals.');
    }
}
