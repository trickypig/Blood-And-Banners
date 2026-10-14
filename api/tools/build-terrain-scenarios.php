<?php
declare(strict_types=1);

use BloodAndBanners\Scenario\AxialCoordinates;
use BloodAndBanners\Scenario\LegacyMapImporter;

spl_autoload_register(static function (string $class): void {
    $prefix = 'BloodAndBanners\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($path)) {
        require $path;
    }
});

if ($argc !== 3) {
    fwrite(STDERR, "usage: php build-terrain-scenarios.php HOME_SOURCE SCENARIO_DIR\n");
    exit(2);
}

$homeSource = $argv[1];
$scenarioDirectory = $argv[2];
if (!is_file($homeSource) || !is_dir($scenarioDirectory)) {
    fwrite(STDERR, "source map or scenario directory not found\n");
    exit(2);
}

/** @return array<string, mixed> */
function scenario_hex(int $q, int $r, string $biome, string $terrain, int $elevation = 0, ?int $playerStart = null, bool $sightBlocker = false, ?string $object = null, ?string $spawnZone = null): array
{
    return [
        'q' => $q,
        'r' => $r,
        'biome' => $biome,
        'terrain' => $terrain,
        'elevation' => $elevation,
        'playerStart' => $playerStart,
        'portal' => $playerStart !== null,
        'spawnZone' => $spawnZone,
        'piece' => null,
        'object' => $object,
        'fountain' => false,
        'sightBlocker' => $sightBlocker,
    ];
}

/** @return array<string, mixed> */
function scenario_hex_from_offset(int $column, int $row, string $biome, string $terrain, int $elevation = 0, ?int $playerStart = null, bool $sightBlocker = false, ?string $object = null, ?string $spawnZone = null): array
{
    $coordinates = AxialCoordinates::fromOddColumnOffset($column, $row);
    return scenario_hex(
        $coordinates['q'],
        $coordinates['r'],
        $biome,
        $terrain,
        $elevation,
        $playerStart,
        $sightBlocker,
        $object,
        $spawnZone
    );
}

/** @param array<string, mixed> $scenario */
function write_scenario(string $path, array $scenario): void
{
    usort(
        $scenario['map']['hexes'],
        static fn(array $left, array $right): int => [$left['r'], $left['q']] <=> [$right['r'], $right['q']]
    );
    $json = json_encode($scenario, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException("could not write {$path}");
    }
}

$fourCornersHexes = [];
for ($row = 0; $row < 11; $row++) {
    for ($column = 0; $column < 13; $column++) {
        $biome = 'plains';
        $terrain = ($row >= 4 && $row <= 6) || $column === 6 ? 'roads' : 'grass';
        $elevation = 0;
        $sightBlocker = false;

        if ($row < 4 && $column < 6) {
            $biome = 'water';
            $terrain = 'open-water';
            $elevation = ($column <= 2 && $row <= 1) ? -1 : 0;
        } elseif ($row < 4 && $column > 6) {
            $biome = 'ice';
            $terrain = 'ice';
            $elevation = ($column >= 10 && $row <= 1) ? 1 : 0;
        } elseif ($row > 6 && $column < 6) {
            $biome = 'lava';
            $terrain = 'lava';
            $elevation = ($column <= 2 && $row >= 9) ? 1 : 0;
        } elseif ($row > 6 && $column > 6) {
            $biome = 'darkness';
            $terrain = 'poison-pits';
            $elevation = ($column >= 10 && $row >= 9) ? -1 : 0;
        }

        if ($column === 6 && in_array($row, [4, 6], true)) {
            $biome = 'stone';
            $terrain = 'crags';
            $elevation = 2;
            $sightBlocker = true;
        } elseif ($column === 6 && $row === 5) {
            $biome = 'stone';
            $terrain = 'stone-path';
            $elevation = 1;
        }

        $start = $column === 0 && $row === 5 ? 1 : ($column === 12 && $row === 5 ? 2 : null);
        $fourCornersHexes[] = scenario_hex_from_offset($column, $row, $biome, $terrain, $elevation, $start, $sightBlocker);
    }
}

$fourCorners = [
    'schemaVersion' => 1,
    'name' => 'Four Corners',
    'modeCompatibility' => ['vanillaPvp'],
    'map' => ['columns' => 13, 'rows' => 11, 'hexes' => $fourCornersHexes],
    'placements' => [],
    'objectives' => [],
    'scoring' => ['target' => 10, 'enemyHero' => 3, 'boss' => 2, 'terrainObject' => 1, 'completeObjectSetBonus' => 2],
];

$provingFieldSpecial = [];
for ($column = 4; $column <= 6; $column++) {
    for ($row = 2; $row <= 6; $row++) {
        $provingFieldSpecial["{$column},{$row}"] = ['biome' => 'stone', 'terrain' => 'stone', 'elevation' => 1];
    }
    $provingFieldSpecial["{$column},4"] = ['biome' => 'stone', 'terrain' => 'stone-path', 'elevation' => 1];
}
foreach ([[5, 3], [5, 5]] as [$column, $row]) {
    $provingFieldSpecial["{$column},{$row}"] = ['biome' => 'stone', 'terrain' => 'crags', 'elevation' => 2];
}
foreach ([[5, 1], [5, 7]] as [$column, $row]) {
    $provingFieldSpecial["{$column},{$row}"] = ['biome' => 'stone', 'terrain' => 'crags', 'elevation' => 2, 'sightBlocker' => true];
}
foreach ([[3, 1], [7, 7], [3, 6], [7, 2]] as [$column, $row]) {
    $provingFieldSpecial["{$column},{$row}"] = ['biome' => 'plains', 'terrain' => 'grass', 'elevation' => 0, 'object' => 'statue', 'sightBlocker' => true];
}
foreach ([[2, 7], [8, 1], [2, 1], [8, 7]] as [$column, $row]) {
    $provingFieldSpecial["{$column},{$row}"] = ['biome' => 'plains', 'terrain' => 'grass', 'elevation' => 1];
}

$provingFieldHexes = [];
for ($row = 0; $row < 9; $row++) {
    for ($column = 0; $column < 11; $column++) {
        $values = [
            'biome' => 'plains',
            'terrain' => $row === 4 ? 'roads' : 'grass',
            'elevation' => 0,
            'sightBlocker' => false,
            'object' => null,
        ];
        $values = array_replace($values, $provingFieldSpecial["{$column},{$row}"] ?? []);
        $start = $column === 1 && $row === 4 ? 1 : ($column === 9 && $row === 4 ? 2 : null);
        $provingFieldHexes[] = scenario_hex_from_offset(
            $column,
            $row,
            $values['biome'],
            $values['terrain'],
            $values['elevation'],
            $start,
            $values['sightBlocker'],
            $values['object']
        );
    }
}

$provingField = [
    'schemaVersion' => 1,
    'name' => 'Proving Field',
    'modeCompatibility' => ['vanillaPvp'],
    'map' => ['columns' => 11, 'rows' => 9, 'hexes' => $provingFieldHexes],
    'placements' => [],
    'objectives' => [['kind' => 'annihilation']],
    'scoring' => ['target' => 10, 'enemyHero' => 3, 'boss' => 2, 'terrainObject' => 1, 'completeObjectSetBonus' => 2],
];

$legacy = json_decode((string)file_get_contents($homeSource), true, flags: JSON_THROW_ON_ERROR);
$homeMap = (new LegacyMapImporter())->import($legacy);
$homeMap['name'] = 'Home Map';
$homeMap['modeCompatibility'] = ['vanillaPvp'];
$homeOne = AxialCoordinates::fromOddColumnOffset(3, 3);
$homeTwo = AxialCoordinates::fromOddColumnOffset(28, 26);
foreach ($homeMap['map']['hexes'] as &$hex) {
    $hex['playerStart'] = match ("{$hex['q']},{$hex['r']}") {
        "{$homeOne['q']},{$homeOne['r']}" => 1,
        "{$homeTwo['q']},{$homeTwo['r']}" => 2,
        default => null,
    };
}
unset($hex);

$voidReachesHexes = [];
for ($row = 0; $row < 25; $row++) {
    for ($column = 0; $column < 25; $column++) {
        $biome = 'plains';
        $terrain = 'grass';
        $elevation = 0;
        $sightBlocker = false;
        $spawn = false;

        if ($row <= 8) {
            if ($column === 8 || $column === 16) {
                if ($row === 3 || $row === 7) {
                    [$biome, $terrain, $elevation] = ['stone', 'stone-path', 1];
                } else {
                    [$biome, $terrain, $elevation, $sightBlocker] = ['stone', 'crags', 3, true];
                }
            } elseif ($column <= 7) {
                $lake = $column >= 2 && $column <= 4 && $row >= 3 && $row <= 5;
                [$biome, $terrain] = ['ice', $lake ? 'ice' : 'snow'];
                $spawn = !$lake && $column <= 6 && $row <= 4;
            } elseif ($column <= 15) {
                $pit = in_array($column, [10, 11, 13, 14], true) && ($row === 6 || $row === 7);
                [$biome, $terrain, $elevation] = ['darkness', $pit ? 'poison-pits' : 'darkness', $row <= 4 ? 2 : 1];
                $spawn = !$pit && $row <= 5;
            } else {
                $lava = ($column === 20 && $row <= 3) || (($column === 18 || $column === 19) && $row === 5);
                [$biome, $terrain] = ['lava', $lava ? 'lava' : 'basalt'];
                $spawn = !$lava && $row <= 3;
            }
        } elseif ($row === 11 || $row === 12) {
            if ($column === 12) {
                [$biome, $terrain] = ['stone', 'stone-path'];
            } elseif (in_array($column, [3, 8, 21], true)) {
                [$biome, $terrain] = ['water', 'shallow-water'];
            } else {
                [$biome, $terrain, $elevation] = ['water', 'open-water', -1];
            }
        } elseif ($row >= 15) {
            if ($column <= 7) {
                $swamp = $column >= 2 && $column <= 4 && $row >= 18 && $row <= 20;
                [$biome, $terrain] = ['jungle', $swamp ? 'swamp' : 'jungle'];
                $spawn = $column <= 3 && $row >= 17;
            } elseif ($column >= 17) {
                [$biome, $terrain] = ['sand', $column % 2 === 0 ? 'desert' : 'dunes'];
                $spawn = $column >= 21 && $row >= 17;
            } elseif (($column === 9 || $column === 15) && $row === 17) {
                [$biome, $terrain, $elevation, $sightBlocker] = ['stone', 'crags', 2, true];
            } elseif ($column === 12 || $row === 20) {
                $terrain = 'roads';
            }
        } elseif ($row >= 13 && $column === 12) {
            $terrain = 'roads';
        }

        $start = $column === 12 && $row === 22 ? 1 : null;
        $voidReachesHexes[] = scenario_hex_from_offset($column, $row, $biome, $terrain, $elevation, $start, $sightBlocker, null, $spawn ? 'void' : null);
    }
}

$voidReaches = [
    'schemaVersion' => 1,
    'name' => 'Void Reaches',
    'modeCompatibility' => ['coop'],
    'map' => ['columns' => 25, 'rows' => 25, 'hexes' => $voidReachesHexes],
    'placements' => [],
    'objectives' => [['kind' => 'defeatAllMonsters']],
    'scoring' => ['target' => 10, 'enemyHero' => 3, 'boss' => 2, 'terrainObject' => 1, 'completeObjectSetBonus' => 2],
    'coop' => ['basePoints' => 4, 'pointsPerHeroPlayer' => 8],
];

write_scenario($scenarioDirectory . DIRECTORY_SEPARATOR . 'four-corners.json', $fourCorners);
write_scenario($scenarioDirectory . DIRECTORY_SEPARATOR . 'home-map.json', $homeMap);
write_scenario($scenarioDirectory . DIRECTORY_SEPARATOR . 'proving-field.json', $provingField);
write_scenario($scenarioDirectory . DIRECTORY_SEPARATOR . 'void-reaches.json', $voidReaches);
