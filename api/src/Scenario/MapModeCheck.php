<?php
declare(strict_types=1);

namespace BloodAndBanners\Scenario;

use BloodAndBanners\Rules\Vanilla\Board;

/**
 * Whether a map can host a game mode, and for how many players: the server's copy of the editor's map check
 * (MapValidation.cs), so a saved map's own claims about its modes are never trusted. Keep the two in step.
 * Room around a start is measured by running Placement's deployment: the first four units stand next to the start,
 * later ones next to units already placed, never on obstacles, landmarks, lava, poison pits, spawn zones, the Void
 * Portal or another start.
 */
final class MapModeCheck
{
    public const DUEL_UNITS = 7;
    public const HERO_UNITS = 8;
    public const ROYALE_UNITS = 8;
    public const MAX_HERO_PLAYERS = 4;
    public const MIN_SPAWN_HEXES = 24;
    public const COOP_REACH = 3;
    public const CLEARANCE = 5;
    private const ANCHORED_UNITS = 4;
    private const DIRECTIONS = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]];

    /** Players the map seats in the mode; 0 when it is not ready for it. */
    public static function players(array $definition, string $mode): int
    {
        return self::check($definition, $mode)['players'];
    }

    /** @return array{players: int, issues: list<string>} */
    public static function check(array $definition, string $mode): array
    {
        $map = self::map($definition);
        [$players, $issues] = match ($mode) {
            'vanillaPvp' => self::duel($map),
            'coop' => self::coop($map),
            'battleRoyale' => self::royale($map),
            default => [0, ['Unknown game mode.']],
        };
        return ['players' => $issues === [] ? $players : 0, 'issues' => $issues];
    }

    private static function duel(array $map): array
    {
        $issues = self::missingStarts($map, [1, 2]);
        if ($issues === []) {
            $occupied = self::blocked($map);
            foreach ([[1, 2], [2, 1]] as [$player, $opponent]) {
                $placed = self::place($map, $map['starts'][$player], $map['starts'][$opponent], self::DUEL_UNITS, $occupied);
                if (count($placed) < self::DUEL_UNITS) {
                    $issues[] = "P{$player}'s start has room for " . count($placed) . ' units; a duel needs ' . self::DUEL_UNITS . '.';
                }
                $occupied += array_fill_keys($placed, true);
            }
            array_push($issues, ...self::crowded($map, [1, 2]));
        }
        return [2, $issues];
    }

    private static function coop(array $map): array
    {
        $issues = self::missingStarts($map, [1]);
        if ($map['portal'] === null) {
            $issues[] = 'Place the Void Portal for the Void Lord.';
        }
        array_push($issues, ...self::fewSpawnHexes($map));
        $players = 0;
        if (isset($map['starts'][1])) {
            $start = $map['starts'][1];
            $spawn = $map['spawn'];
            usort($spawn, static fn(string $a, string $b): int => [self::distance($start, $a), $a] <=> [self::distance($start, $b), $b]);
            $facing = $spawn[0] ?? $start;
            $room = count(self::place($map, $start, $facing, self::HERO_UNITS * self::MAX_HERO_PLAYERS, self::blocked($map),
                static fn(string $key): bool => self::distance($start, $key) <= self::COOP_REACH));
            $players = intdiv($room, self::HERO_UNITS);
            if ($players === 0) {
                $issues[] = "P1's start has room for {$room} units within " . self::COOP_REACH . ' hexes; each hero player needs ' . self::HERO_UNITS . '.';
            }
            array_push($issues, ...self::crowded($map, [1]));
        }
        return [$players, $issues];
    }

    private static function royale(array $map): array
    {
        $count = $map['starts'] === [] ? 0 : max(array_keys($map['starts']));
        $numbered = $count > 0 ? range(1, $count) : [];
        $gaps = array_values(array_filter($numbered, static fn(int $p): bool => !isset($map['starts'][$p])));
        $issues = [];
        if ($count < 2) {
            $issues[] = 'Battle royale needs at least P1 and P2 starts.';
        } elseif ($gaps !== []) {
            $issues[] = 'Battle royale starts must be numbered in order; add ' . implode(' and ', array_map(static fn(int $p): string => "P{$p}", $gaps)) . ' or renumber the starts.';
        }
        array_push($issues, ...self::fewSpawnHexes($map));
        if ($count >= 2 && $gaps === []) {
            $occupied = self::blocked($map);
            foreach ($numbered as $player) {
                $home = $map['starts'][$player];
                $rivals = array_map(static fn(int $p): string => $map['starts'][$p], array_values(array_diff($numbered, [$player])));
                usort($rivals, static fn(string $a, string $b): int => [self::distance($home, $a), $a] <=> [self::distance($home, $b), $b]);
                $placed = self::place($map, $home, $rivals[0], self::ROYALE_UNITS, $occupied);
                if (count($placed) < self::ROYALE_UNITS) {
                    $issues[] = "P{$player}'s start has room for " . count($placed) . ' units; battle royale needs ' . self::ROYALE_UNITS . '.';
                }
                $occupied += array_fill_keys($placed, true);
            }
            array_push($issues, ...self::crowded($map, $numbered));
        }
        return [$count, $issues];
    }

    private static function missingStarts(array $map, array $players): array
    {
        return array_values(array_map(
            static fn(int $p): string => "Place a P{$p} start.",
            array_filter($players, static fn(int $p): bool => !isset($map['starts'][$p]))
        ));
    }

    private static function fewSpawnHexes(array $map): array
    {
        $count = count($map['spawn']);
        return $count < self::MIN_SPAWN_HEXES ? ['The map needs at least ' . self::MIN_SPAWN_HEXES . " Void spawn hexes; it has {$count}."] : [];
    }

    /** Objectives (landmarks) and pickups (items) must stand more than CLEARANCE hexes from every start in play. */
    private static function crowded(array $map, array $players): array
    {
        $issues = [];
        foreach ($players as $player) {
            foreach ($map['hexes'] as $key => $hex) {
                $kind = $hex['object'] ?? null;
                if (!in_array($kind, Board::LANDMARKS, true) && !in_array($kind, Board::ITEMS, true)) {
                    continue;
                }
                $distance = self::distance($map['starts'][$player], (string)$key);
                if ($distance <= self::CLEARANCE) {
                    $issues[] = "The {$kind} at {$key} is {$distance} hexes from P{$player}'s start; keep objectives and potions more than " . self::CLEARANCE . ' hexes away.';
                }
            }
        }
        return $issues;
    }

    private static function map(array $definition): array
    {
        $map = ['hexes' => [], 'starts' => [], 'spawn' => [], 'portal' => null];
        foreach ($definition['map']['hexes'] ?? [] as $hex) {
            $key = "{$hex['q']},{$hex['r']}";
            $map['hexes'][$key] = $hex;
            if (($hex['playerStart'] ?? null) !== null) {
                $map['starts'][(int)$hex['playerStart']] = $key;
            }
            if (($hex['spawnZone'] ?? null) === 'void') {
                $map['spawn'][] = $key;
            }
            if (($hex['voidPortal'] ?? false) === true && $map['portal'] === null) {
                $map['portal'] = $key;
            }
        }
        return $map;
    }

    /** Hexes no unit may deploy onto, whoever is placing. @return array<string, true> */
    private static function blocked(array $map): array
    {
        $blocked = [];
        foreach ($map['hexes'] as $key => $hex) {
            $object = $hex['object'] ?? null;
            $deployable = !in_array($object, Board::OBSTACLES, true) && !in_array($object, Board::LANDMARKS, true)
                && !($hex['sightBlocker'] ?? false) && !in_array($hex['terrain'] ?? null, ['lava', 'poison-pits'], true)
                && ($hex['spawnZone'] ?? null) !== 'void' && !($hex['voidPortal'] ?? false);
            if (!$deployable || ($hex['playerStart'] ?? null) !== null) {
                $blocked[(string)$key] = true;
            }
        }
        return $blocked;
    }

    /** Placement's deployment of up to $units units; returns the hexes filled before room ran out. @return list<string> */
    private static function place(array $map, string $home, string $opponent, int $units, array $occupied, ?callable $within = null): array
    {
        $placed = [];
        for ($index = 0; $index < $units; $index++) {
            $anchored = $index < self::ANCHORED_UNITS;
            $candidates = [];
            foreach ($anchored ? [$home] : $placed as $anchor) {
                [$q, $r] = array_map('intval', explode(',', $anchor));
                foreach (self::DIRECTIONS as [$dq, $dr]) {
                    $key = ($q + $dq) . ',' . ($r + $dr);
                    if (isset($map['hexes'][$key]) && $key !== $home && $key !== $opponent && !isset($occupied[$key])
                        && !in_array($key, $placed, true) && ($within === null || $within($key))) {
                        $candidates[$key] = $key;
                    }
                }
            }
            if ($candidates === []) {
                break;
            }
            $rank = static function (string $key) use ($anchored, $home, $opponent): array {
                [$q, $r] = array_map('intval', explode(',', $key));
                return $anchored
                    ? [self::distance($key, $opponent), 0, $q, $r]
                    : [self::distance($key, $home), self::distance($key, $opponent), $q, $r];
            };
            $candidates = array_values($candidates);
            usort($candidates, static fn(string $a, string $b): int => $rank($a) <=> $rank($b));
            $placed[] = $candidates[0];
        }
        return $placed;
    }

    private static function distance(string $from, string $to): int
    {
        [$q1, $r1] = array_map('intval', explode(',', $from));
        [$q2, $r2] = array_map('intval', explode(',', $to));
        $dq = $q2 - $q1;
        $dr = $r2 - $r1;
        return intdiv(abs($dq) + abs($dr) + abs($dq + $dr), 2);
    }
}
