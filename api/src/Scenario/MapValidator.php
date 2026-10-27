<?php
declare(strict_types=1);
namespace BloodAndBanners\Scenario;

final class MapValidator
{
    private const TERRAINS = [
        'plains' => ['grass', 'roads'], 'water' => ['open-water', 'shallow-water'],
        'stone' => ['stone-path', 'crags'], 'sand' => ['desert', 'dunes'],
        'jungle' => ['jungle', 'swamp'], 'darkness' => ['darkness', 'poison-pits'],
        'lava' => ['basalt', 'lava'], 'ice' => ['snow', 'ice'],
    ];

    public static function validate(mixed $definition): array
    {
        if (strlen(json_encode($definition, JSON_THROW_ON_ERROR)) > 2 * 1024 * 1024) {
            throw new MapException(413, 'mapTooLarge', 'Maps must be no larger than 2 MiB.');
        }
        $definition = self::structure($definition);
        self::check(is_array($definition), 'A map definition is required.');
        self::check(($definition['schemaVersion'] ?? null) === 1, 'Unsupported map schema.');
        $name = $definition['name'] ?? null;
        self::check(is_string($name) && trim($name) !== '' && preg_match_all('/./us', $name) <= 120, 'Map names must contain 1–120 characters.');
        $modes = $definition['modeCompatibility'] ?? null;
        self::check(is_array($modes) && array_is_list($modes) && count($modes) > 0, 'Choose at least one game mode.');
        foreach ($modes as $mode) {
            self::check(in_array($mode, ['vanillaPvp', 'coop', 'battleRoyale'], true), 'Invalid game mode.');
        }
        $map = $definition['map'] ?? null;
        self::check(is_array($map), 'Map data is required.');
        foreach (['columns', 'rows'] as $dimension) {
            self::integer($map[$dimension] ?? null, 1, 100, 'Map dimensions must be between 1 and 100.');
        }
        $hexes = $map['hexes'] ?? null;
        self::check(is_array($hexes) && array_is_list($hexes) && count($hexes) <= 10000, 'Maps must contain at most 10,000 hexes.');
        $seen = [];
        if (isset($map['editorEmptyCells'])) {
            $emptyCells = $map['editorEmptyCells'];
            self::check(is_array($emptyCells) && array_is_list($emptyCells) && count($emptyCells) <= 10000, 'Invalid empty editor coordinates.');
            foreach ($emptyCells as $cell) {
                $cell = self::record($cell);
                self::check(is_array($cell), 'Invalid empty editor coordinate.');
                self::integer($cell['q'] ?? null, -10000, 10000, 'Invalid empty editor coordinate.');
                self::integer($cell['r'] ?? null, -10000, 10000, 'Invalid empty editor coordinate.');
            }
        }
        $voidPortals = 0;
        foreach ($hexes as $hex) {
            self::check(is_array($hex), 'Invalid hex.');
            self::integer($hex['q'] ?? null, -10000, 10000, 'Invalid axial coordinates.');
            self::integer($hex['r'] ?? null, -10000, 10000, 'Invalid axial coordinates.');
            $key = $hex['q'] . ',' . $hex['r'];
            self::check(!isset($seen[$key]), 'Hex coordinates must be unique.');
            $seen[$key] = true;
            $biome = $hex['biome'] ?? null;
            self::check(is_string($biome) && isset(self::TERRAINS[$biome]) && in_array($hex['terrain'] ?? null, self::TERRAINS[$biome], true), 'Invalid biome or terrain.');
            self::integer($hex['elevation'] ?? null, -1000, 1000, 'Invalid elevation.');
            if (($hex['playerStart'] ?? null) !== null) {
                self::integer($hex['playerStart'], 1, 4, 'Player starts must be 1–4.');
            }
            if (($hex['radius'] ?? null) !== null) {
                self::integer($hex['radius'], 1, 4, 'Rune radius must be 1–4.');
            }
            foreach (['portal', 'fountain', 'sightBlocker'] as $flag) {
                self::check(isset($hex[$flag]) && is_bool($hex[$flag]), "Invalid {$flag} flag.");
            }
            if (array_key_exists('voidPortal', $hex)) {
                self::check(is_bool($hex['voidPortal']), 'Invalid voidPortal flag.');
                $voidPortals += $hex['voidPortal'] ? 1 : 0;
            }
            foreach (['spawnZone', 'piece', 'object'] as $field) {
                self::check(($hex[$field] ?? null) === null || (is_string($hex[$field]) && strlen($hex[$field]) <= 120), "Invalid {$field}.");
            }
        }
        self::check($voidPortals <= 1, 'A map holds at most one Void Portal.');
        foreach (['placements', 'objectives'] as $field) {
            self::check(isset($definition[$field]) && is_array($definition[$field]) && array_is_list($definition[$field]), "Invalid {$field}.");
        }
        self::check(is_array($definition['scoring'] ?? null), 'Scoring data is required.');
        foreach (['target', 'enemyHero', 'boss', 'terrainObject', 'completeObjectSetBonus'] as $field) {
            self::integer($definition['scoring'][$field] ?? null, 0, 1000000, "Invalid scoring {$field}.");
        }
        $definition['name'] = trim($name);
        return $definition;
    }

    /** Convert only known record containers; arbitrary metadata keeps its exact JSON shape. */
    public static function structure(mixed $definition): mixed
    {
        $definition = self::record($definition);
        if (!is_array($definition)) return $definition;
        if (isset($definition['map'])) {
            $definition['map'] = self::record($definition['map']);
            if (is_array($definition['map']) && is_array($definition['map']['hexes'] ?? null))
                $definition['map']['hexes'] = array_map(self::record(...), $definition['map']['hexes']);
        }
        if (isset($definition['scoring'])) $definition['scoring'] = self::record($definition['scoring']);
        return $definition;
    }

    private static function record(mixed $value): mixed
    {
        return $value instanceof \stdClass ? get_object_vars($value) : $value;
    }

    private static function integer(mixed $value, int $min, int $max, string $message): void
    {
        self::check(is_int($value) && $value >= $min && $value <= $max, $message);
    }

    private static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new MapException(400, 'invalidMap', $message);
        }
    }
}
