<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

final class Board
{
    /** Obstacles fill their hex: nothing may enter it (flying units included) or see through it. */
    public const OBSTACLES = ['tree', 'rock'];
    /** Landmarks fill their hex for movement (flying included) but can be seen past. */
    public const LANDMARKS = ['shrine', 'fire-crystal', 'forest-crystal', 'ice-crystal'];
    /** Nexus points (runes) block nothing; a unit may stand on them. Their auras are in Runes. */
    public const NEXUS_POINTS = ['rune-of-focus', 'rune-of-strength', 'rune-of-warding'];
    /** Items (potions) block nothing; a player unit that comes to rest on one drinks it (Potions). */
    public const ITEMS = ['power-potion', 'speed-potion', 'health-potion'];
    /** Object ids from saved maps that have since been renamed. */
    private const RENAMED = ['rune-of-magic' => 'rune-of-focus'];

    /** Whether a scenario hex blocks sight: an obstacle, or the art-less flag. */
    public static function blocksSight(array $hex): bool
    {
        return (bool)($hex['sightBlocker'] ?? false) || in_array($hex['object'] ?? null, self::OBSTACLES, true);
    }

    /** Whether a scenario hex blocks movement, flying included: anything that blocks sight, or a landmark. */
    public static function blocksMovement(array $hex): bool
    {
        return self::blocksSight($hex) || in_array($hex['object'] ?? null, self::LANDMARKS, true);
    }

    /** Whether nothing may enter an engine board tile. A sight blocker always is, so boards built from flags alone need no extra key. */
    public static function impassable(array $tile): bool
    {
        return (bool)($tile['impassable'] ?? $tile['sightBlocker'] ?? false);
    }

    /** The scenario hex's object, with renamed legacy ids read as their new names. */
    public static function objectKind(array $hex): ?string
    {
        return self::renamed(isset($hex['object']) ? (string)$hex['object'] : null);
    }

    /** A map object kind under its current id: old saved names read as their replacements. */
    public static function renamed(?string $kind): ?string
    {
        return $kind === null ? null : (self::RENAMED[$kind] ?? $kind);
    }

    /** What a projected hex shows: the live board's object once a game is in play (potions get drunk), else the map's. */
    public static function projectedObject(array $hex, ?array $board): ?string
    {
        $key = Hex::key((int)$hex['q'], (int)$hex['r']);
        if ($board !== null && isset($board[$key]) && array_key_exists('object', $board[$key])) {
            return self::renamed($board[$key]['object'] === null ? null : (string)$board[$key]['object']);
        }
        return self::objectKind($hex);
    }

    public static function fromScenario(array $definition): array
    {
        $board = [];
        foreach ($definition['map']['hexes'] as $hex) {
            $board[Hex::key((int)$hex['q'], (int)$hex['r'])] = [
                'elevation' => (int)$hex['elevation'],
                'sightBlocker' => self::blocksSight($hex),
                'impassable' => self::blocksMovement($hex),
                'playerStart' => isset($hex['playerStart']) ? (int)$hex['playerStart'] : null,
                'biome' => (string)($hex['biome'] ?? 'plains'),
                'terrain' => (string)($hex['terrain'] ?? 'grass'),
                'spawnZone' => isset($hex['spawnZone']) ? (string)$hex['spawnZone'] : null,
                'object' => self::objectKind($hex),
                'radius' => isset($hex['radius']) ? (int)$hex['radius'] : 1,
            ];
        }
        return $board;
    }

    public static function homes(array $board): array
    {
        $homes = [];
        foreach ($board as $key => $hex) {
            if ($hex['playerStart'] !== null) {
                $homes[$hex['playerStart']] = (string)$key;
            }
        }
        ksort($homes);
        return $homes;
    }
}
