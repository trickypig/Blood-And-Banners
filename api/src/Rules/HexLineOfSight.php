<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules;

use InvalidArgumentException;

final class HexLineOfSight
{
    private const DIRECTIONS = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]];

    public function isVisible(string $startKey, string $targetKey, array $board): bool
    {
        if (!isset($board[$startKey], $board[$targetKey])) {
            throw new InvalidArgumentException('Line-of-sight endpoints must exist on the board.');
        }
        if ($startKey === $targetKey) {
            return true;
        }
        $endpointHeight = max((int)$board[$startKey]['elevation'], (int)$board[$targetKey]['elevation']);
        // Some shortest ray is clear iff the target can be reached by stepping one hex closer at a time
        // through clear hexes only. Walking the layers costs O(distance²) instead of one walk per ray.
        [$targetQ, $targetR] = self::coordinates($targetKey);
        $layer = [self::coordinates($startKey)];
        $remaining = self::distance($layer[0][0], $layer[0][1], $targetQ, $targetR);
        while ($remaining > 1) {
            $next = [];
            foreach ($layer as [$q, $r]) {
                foreach (self::DIRECTIONS as [$deltaQ, $deltaR]) {
                    $nextQ = $q + $deltaQ;
                    $nextR = $r + $deltaR;
                    $key = "{$nextQ},{$nextR}";
                    if (isset($next[$key]) || !isset($board[$key]) || self::distance($nextQ, $nextR, $targetQ, $targetR) !== $remaining - 1) {
                        continue;
                    }
                    $hex = $board[$key];
                    if (($hex['occupantId'] ?? null) === null
                        && !(bool)($hex['sightBlocker'] ?? false)
                        && (int)($hex['elevation'] ?? 0) < $endpointHeight + 2) {
                        $next[$key] = [$nextQ, $nextR];
                    }
                }
            }
            if ($next === []) {
                return false;
            }
            $layer = array_values($next);
            $remaining--;
        }
        return true;
    }

    public function shortestRays(string $startKey, string $targetKey, array $board): array
    {
        [$startQ, $startR] = self::coordinates($startKey);
        [$targetQ, $targetR] = self::coordinates($targetKey);
        $rays = [];
        $this->walk(
            $startQ,
            $startR,
            $targetQ,
            $targetR,
            $board,
            [$startKey],
            $rays,
        );
        return $rays;
    }

    private function walk(
        int $q,
        int $r,
        int $targetQ,
        int $targetR,
        array $board,
        array $path,
        array &$rays,
    ): void {
        if ($q === $targetQ && $r === $targetR) {
            $rays[] = $path;
            return;
        }
        $remaining = self::distance($q, $r, $targetQ, $targetR);
        foreach (self::DIRECTIONS as [$deltaQ, $deltaR]) {
            $nextQ = $q + $deltaQ;
            $nextR = $r + $deltaR;
            $key = "{$nextQ},{$nextR}";
            if (!isset($board[$key]) || self::distance($nextQ, $nextR, $targetQ, $targetR) !== $remaining - 1) {
                continue;
            }
            $this->walk($nextQ, $nextR, $targetQ, $targetR, $board, [...$path, $key], $rays);
        }
    }

    private static function coordinates(string $key): array
    {
        if (preg_match('/^(-?\d+),(-?\d+)$/', $key, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid axial hex key {$key}.");
        }
        return [(int)$matches[1], (int)$matches[2]];
    }

    private static function distance(int $fromQ, int $fromR, int $toQ, int $toR): int
    {
        $deltaQ = $toQ - $fromQ;
        $deltaR = $toR - $fromR;
        return (abs($deltaQ) + abs($deltaR) + abs($deltaQ + $deltaR)) / 2;
    }
}
