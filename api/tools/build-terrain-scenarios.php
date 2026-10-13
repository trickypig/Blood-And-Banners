<?php
declare(strict_types=1);

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
function scenario_hex(int $q, int $r, string $biome, string $terrain, int $elevation = 0, ?int $playerStart = null, bool $sightBlocker = false): array
{
    return [
        'q' => $q,
        'r' => $r,
        'biome' => $biome,
        'terrain' => $terrain,
        'elevation' => $elevation,
        'playerStart' => $playerStart,
        'portal' => $playerStart !== null,
        'spawnZone' => null,
        'piece' => null,
        'object' => null,
        'fountain' => false,
        'sightBlocker' => $sightBlocker,
    ];
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
for ($r = 0; $r < 11; $r++) {
    for ($q = 0; $q < 13; $q++) {
        $biome = 'plains';
        $terrain = ($r >= 4 && $r <= 6) || $q === 6 ? 'roads' : 'grass';
        $elevation = 0;
        $sightBlocker = false;

        if ($r < 4 && $q < 6) {
            $biome = 'water';
            $terrain = 'open-water';
            $elevation = ($q <= 2 && $r <= 1) ? -1 : 0;
        } elseif ($r < 4 && $q > 6) {
            $biome = 'ice';
            $terrain = 'ice';
            $elevation = ($q >= 10 && $r <= 1) ? 1 : 0;
        } elseif ($r > 6 && $q < 6) {
            $biome = 'lava';
            $terrain = 'lava';
            $elevation = ($q <= 2 && $r >= 9) ? 1 : 0;
        } elseif ($r > 6 && $q > 6) {
            $biome = 'darkness';
            $terrain = 'poison-pits';
            $elevation = ($q >= 10 && $r >= 9) ? -1 : 0;
        }

        if ($q === 6 && in_array($r, [4, 6], true)) {
            $biome = 'stone';
            $terrain = 'crags';
            $elevation = 2;
            $sightBlocker = true;
        } elseif ($q === 6 && $r === 5) {
            $biome = 'stone';
            $terrain = 'stone-path';
            $elevation = 1;
        }

        $start = $q === 0 && $r === 5 ? 1 : ($q === 12 && $r === 5 ? 2 : null);
        $fourCornersHexes[] = scenario_hex($q, $r, $biome, $terrain, $elevation, $start, $sightBlocker);
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

$legacy = json_decode((string)file_get_contents($homeSource), true, flags: JSON_THROW_ON_ERROR);
$homeMap = (new LegacyMapImporter())->import($legacy);
$homeMap['name'] = 'Home Map';
$homeMap['modeCompatibility'] = ['vanillaPvp'];
foreach ($homeMap['map']['hexes'] as &$hex) {
    $hex['playerStart'] = match ("{$hex['q']},{$hex['r']}") {
        '3,3' => 1,
        '28,26' => 2,
        default => null,
    };
}
unset($hex);

write_scenario($scenarioDirectory . DIRECTORY_SEPARATOR . 'four-corners.json', $fourCorners);
write_scenario($scenarioDirectory . DIRECTORY_SEPARATOR . 'home-map.json', $homeMap);
