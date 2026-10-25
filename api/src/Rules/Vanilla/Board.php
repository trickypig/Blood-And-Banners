<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

final class Board
{
    /** Obstacles fill their hex: nothing may enter it (flying units included) or see through it. */
    public const OBSTACLES = ['tree', 'rock'];
    /** Landmarks fill their hex for movement (flying included) but can be seen past. */
    public const LANDMARKS = ['shrine', 'fire-crystal', 'forest-crystal', 'ice-crystal'];
    /** Nexus points and items block nothing; a unit may stand on them. They have no rules effect yet. */
    public const NEXUS_POINTS = ['rune-of-magic', 'rune-of-strength', 'rune-of-warding'];
    public const ITEMS = ['power-potion', 'speed-potion', 'health-potion'];

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
                'object' => isset($hex['object']) ? (string)$hex['object'] : null,
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
