<?php
declare(strict_types=1);

use BloodAndBanners\Database\ConnectionFactory;
use BloodAndBanners\Database\Migrator;
use BloodAndBanners\Rules\Vanilla\Board;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\Placement;
use BloodAndBanners\Scenario\LegacyMapImporter;
use BloodAndBanners\Scenario\ScenarioService;

function built_in_scenario(string $fileName): array
{
    return json_decode(
        (string)file_get_contents(dirname(__DIR__) . "/scenarios/{$fileName}.json"),
        true,
        flags: JSON_THROW_ON_ERROR
    );
}

function expect_connected_scenario(array $scenario, string $message): void
{
    $board = Board::fromScenario($scenario);
    $traversable = array_filter($board, static fn(array $hex): bool => !$hex['sightBlocker']);
    $start = array_key_first($traversable);
    expect_true($start !== null, "{$message}: expected at least one traversable hex");

    $seen = [$start => true];
    $queue = [$start];
    while ($queue !== []) {
        $current = array_shift($queue);
        foreach (Hex::neighbors($current, $board) as $neighbor) {
            if ($board[$neighbor]['sightBlocker'] || isset($seen[$neighbor])) {
                continue;
            }
            $seen[$neighbor] = true;
            $queue[] = $neighbor;
        }
    }

    expect_same(count($traversable), count($seen), $message);
}

function expect_canonical_rectangle(array $scenario, string $message): void
{
    $columns = (int)$scenario['map']['columns'];
    $rows = (int)$scenario['map']['rows'];
    $cells = [];
    foreach ($scenario['map']['hexes'] as $hex) {
        $column = (int)$hex['r'];
        $row = (int)$hex['q'] + intdiv($column, 2);
        expect_true($column >= 0 && $column < $columns, "{$message}: column {$column} should be in bounds");
        expect_true($row >= 0 && $row < $rows, "{$message}: row {$row} should be in bounds");
        $cells["{$column},{$row}"] = true;
    }

    expect_same($columns * $rows, count($cells), "{$message}: every rectangular cell should exist exactly once");
}

function scenario_tests(): array
{
    return [
        'generated scenario sight blockers have visible obstacles' => static function (): void {
            $directory = sys_get_temp_dir() . '/bb-generated-' . bin2hex(random_bytes(8));
            mkdir($directory);
            $names = ['four-corners', 'home-map', 'proving-field', 'void-reaches'];
            try {
                $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/tools/build-terrain-scenarios.php')
                    . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/data/Home_Map.json') . ' ' . escapeshellarg($directory);
                exec($command, $output, $status);
                expect_same(0, $status, 'scenario generator succeeds');
                foreach (array_merge(glob($directory . '/*.json'), glob(dirname(__DIR__) . '/scenarios/*.json')) as $path) {
                    $name = basename($path, '.json');
                    $scenario = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                    foreach ($scenario['map']['hexes'] as $hex) {
                        if (!$hex['sightBlocker']) continue;
                        expect_true(in_array($hex['object'], Board::OBSTACLES, true), "{$name} blocker {$hex['q']},{$hex['r']} has obstacle art");
                    }
                }
            } finally {
                foreach ($names as $name) {
                    $file = $directory . '/' . $name . '.json';
                    if (is_file($file)) unlink($file);
                }
                rmdir($directory);
            }
        },
        'legacy editor maps normalize into the versioned scenario schema' => static function (): void {
            $legacy = [
                'name' => 'Old Crossing',
                'columns' => 3,
                'rows' => 2,
                'hexes' => [
                    ['col' => 0, 'row' => 0, 'terrain' => 'grass', 'height' => 2, 'playerStart' => 1],
                    ['col' => 1, 'row' => 0, 'terrain' => 'lava', 'object' => 'fountain'],
                    ['col' => 2, 'row' => 1, 'terrain' => 'ice'],
                ],
            ];

            $scenario = (new LegacyMapImporter())->import($legacy);

            expect_same(1, $scenario['schemaVersion'], 'scenario schema version should be explicit');
            expect_same(
                [[0, 0], [0, 1], [0, 2]],
                array_map(static fn(array $hex): array => [$hex['q'], $hex['r']], $scenario['map']['hexes']),
                'odd-column offset positions should become canonical axial coordinates'
            );
            expect_same('plains', $scenario['map']['hexes'][0]['biome'], 'grass should map to the plains biome');
            expect_same(2, $scenario['map']['hexes'][0]['elevation'], 'legacy height should become elevation');
            expect_same(1, $scenario['map']['hexes'][0]['playerStart'], 'player starts should be retained');
            expect_same(['vanillaPvp', 'coop', 'battleRoyale'], $scenario['modeCompatibility'], 'legacy maps should remain playable in every mode');
        },

        'scenario imports preserve canonical axial coordinates' => static function (): void {
            $scenario = (new LegacyMapImporter())->import([
                'name' => 'Axial Crossing',
                'columns' => 1,
                'rows' => 1,
                'hexes' => [['q' => -3, 'r' => 7, 'terrain' => 'grass']],
            ]);

            expect_same(
                [-3, 7],
                [$scenario['map']['hexes'][0]['q'], $scenario['map']['hexes'][0]['r']],
                'canonical axial coordinates must not be converted again'
            );
        },

        'scenario imports reject incomplete coordinate pairs' => static function (): void {
            try {
                (new LegacyMapImporter())->import([
                    'name' => 'Broken Crossing',
                    'columns' => 1,
                    'rows' => 1,
                    'hexes' => [['col' => 0, 'terrain' => 'grass']],
                ]);
                throw new RuntimeException('incomplete coordinates were accepted');
            } catch (InvalidArgumentException $error) {
                expect_same('invalidLegacyMap', $error->getMessage(), 'incomplete coordinates should be rejected cleanly');
            }
        },

        'saved scenario revisions are immutable and owner restricted' => static function (): void {
            $db = ConnectionFactory::sqliteMemory();
            (new Migrator($db))->migrate();
            $db->prepare('INSERT INTO users (id, email, password_hash, display_name, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute(['owner-1', 'owner@example.com', 'hash', 'Owner', gmdate('Y-m-d\TH:i:s\Z')]);
            $service = new ScenarioService($db);
            $definition = (new LegacyMapImporter())->import([
                'name' => 'Old Crossing', 'columns' => 1, 'rows' => 1,
                'hexes' => [['col' => 0, 'row' => 0, 'terrain' => 'grass']],
            ]);

            $first = $service->create('owner-1', $definition);
            $changed = $definition;
            $changed['name'] = 'Old Crossing Remastered';
            $second = $service->addRevision($first['scenarioId'], 'owner-1', $changed);

            expect_same(1, $first['revision'], 'first revision number should be one');
            expect_same(2, $second['revision'], 'next revision number should increment');
            expect_same('Old Crossing', $service->getRevision($first['revisionId'])['name'], 'old revision must not change');
            expect_same('Old Crossing Remastered', $service->getRevision($second['revisionId'])['name'], 'new revision should contain edits');

            try {
                $service->addRevision($first['scenarioId'], 'different-owner', $changed);
                throw new RuntimeException('non-owner edited the scenario');
            } catch (DomainException $error) {
                expect_same('scenarioOwnerRequired', $error->getMessage(), 'only owners can create revisions');
            }
        },

        'void reaches has land and river spawn zones and two routes to every land zone' => static function (): void {
            $scenario = built_in_scenario('void-reaches');
            expect_same('Void Reaches', $scenario['name'], 'the map uses its approved name');
            expect_same(['coop'], $scenario['modeCompatibility'], 'the map is Co-op only');
            expect_same(['basePoints' => 4, 'pointsPerHeroPlayer' => 8], $scenario['coop'], 'the budget is set by the scenario');
            expect_canonical_rectangle($scenario, 'void reaches');
            expect_same(625, count($scenario['map']['hexes']), 'every hex exists');

            $board = \BloodAndBanners\Rules\Vanilla\Board::fromScenario($scenario);
            $starts = array_keys(array_filter($board, static fn(array $hex): bool => $hex['playerStart'] !== null));
            expect_same(1, count($starts), 'there is one shared hero start');
            $start = $starts[0];
            $spawn = array_keys(array_filter($board, static fn(array $hex): bool => $hex['spawnZone'] === 'void'));
            expect_true(count($spawn) >= 100 && count($spawn) <= 190, 'spawn zones include the original regions and a small river area');
            foreach ($spawn as $key) {
                expect_true(\BloodAndBanners\Rules\Vanilla\Hex::distance($start, $key) >= 7, "spawn hex {$key} is at least 7 from the start");
                expect_true(in_array(\BloodAndBanners\Rules\Vanilla\Terrain::type($board[$key]), [null, 'water'], true), "spawn hex {$key} is land or water");
                expect_true(!$board[$key]['sightBlocker'], "spawn hex {$key} is enterable");
            }

            $probe = array_map(static fn(int $i): string => "probe-{$i}", range(1, 32));
            $placed = \BloodAndBanners\Rules\Vanilla\Placement::place($board, $start, $spawn[0], $probe, array_fill_keys($spawn, true));
            expect_same(32, count($placed), 'four full hero players fit around the start');

            $offsets = [[3, 7], [12, 6], [22, 7]];
            foreach ($offsets as [$column, $row]) {
                $axial = \BloodAndBanners\Scenario\AxialCoordinates::fromOddColumnOffset($column, $row);
                $key = "{$axial['q']},{$axial['r']}";
                expect_same(null, \BloodAndBanners\Rules\Vanilla\Terrain::type($board[$key]), "fountain site {$key} is neutral");
                expect_same(null, $board[$key]['spawnZone'], "fountain site {$key} is not a spawn hex");
                expect_true(!$board[$key]['sightBlocker'], "fountain site {$key} is enterable");
            }

            // Neutral walking reachability: step to a neighbour within 1 level, never into hazards or blockers.
            $reach = static function (array $blocked) use ($board, $start): array {
                $seen = [$start => true];
                $queue = [$start];
                while ($queue !== []) {
                    $hex = array_shift($queue);
                    foreach (\BloodAndBanners\Rules\Vanilla\Hex::neighbors($hex, $board) as $next) {
                        if (isset($seen[$next]) || isset($blocked[$next]) || $board[$next]['sightBlocker']
                            || \BloodAndBanners\Rules\Vanilla\Terrain::type($board[$next]) !== null
                            || abs($board[$next]['elevation'] - $board[$hex]['elevation']) > 1) {
                            continue;
                        }
                        $seen[$next] = true;
                        $queue[] = $next;
                    }
                }
                return $seen;
            };
            $zones = [
                'Frost Reach' => [[0, 6], [0, 4]], 'The Hollow' => [[9, 15], [0, 5]], 'Ember Wastes' => [[17, 24], [0, 3]],
                'Thornwild' => [[0, 3], [17, 24]], 'Shifting Dunes' => [[21, 24], [17, 24]],
            ];
            $crossings = [];
            foreach ([3, 8, 12, 21] as $column) {
                $crossing = [];
                foreach ([11, 12] as $row) {
                    $axial = \BloodAndBanners\Scenario\AxialCoordinates::fromOddColumnOffset($column, $row);
                    $crossing["{$axial['q']},{$axial['r']}"] = true;
                }
                $crossings[] = $crossing;
            }
            foreach ($zones as $zone => [[$c0, $c1], [$r0, $r1]]) {
                $zoneSpawn = [];
                for ($column = $c0; $column <= $c1; $column++) {
                    for ($row = $r0; $row <= $r1; $row++) {
                        $axial = \BloodAndBanners\Scenario\AxialCoordinates::fromOddColumnOffset($column, $row);
                        $key = "{$axial['q']},{$axial['r']}";
                        if ($board[$key]['spawnZone'] === 'void') {
                            $zoneSpawn[] = $key;
                        }
                    }
                }
                expect_true($zoneSpawn !== [], "{$zone} has spawn hexes");
                foreach (array_merge([[]], $crossings) as $blocked) {
                    $seen = $reach($blocked);
                    expect_true(array_filter($zoneSpawn, static fn(string $key): bool => isset($seen[$key])) !== [], "{$zone} stays reachable with any one crossing closed");
                }
            }
        },

        'built in scenarios cover every game mode with valid maps' => static function (): void {
            $files = glob(dirname(__DIR__) . '/scenarios/*.json') ?: [];
            expect_same(11, count($files), 'six curated scenarios and five incursion drafts should ship');
            $coveredModes = [];
            foreach ($files as $file) {
                $scenario = json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                expect_same(1, $scenario['schemaVersion'], 'built-in schema should be current');
                expect_true(count($scenario['map']['hexes']) >= 7, 'built-in maps should contain a playable hex cluster');
                $coveredModes = array_merge($coveredModes, $scenario['modeCompatibility']);
            }
            foreach (['vanillaPvp', 'coop', 'battleRoyale'] as $mode) {
                expect_true(in_array($mode, $coveredModes, true), "built-ins should cover {$mode}");
            }
        },

        'four corners has four hazard regions and two safe seven unit deployments' => static function (): void {
            $scenario = built_in_scenario('four-corners');
            expect_same('Four Corners', $scenario['name'], 'the map should use its approved name');
            expect_same(['vanillaPvp'], $scenario['modeCompatibility'], 'the map should be Vanilla-only');
            expect_same(13, $scenario['map']['columns'], 'the map should be 13 columns wide');
            expect_same(11, $scenario['map']['rows'], 'the map should be 11 rows tall');
            expect_same(143, count($scenario['map']['hexes']), 'the rectangular arena should have every hex');

            $terrainCounts = array_count_values(array_column($scenario['map']['hexes'], 'terrain'));
            foreach (['open-water', 'ice', 'lava', 'poison-pits'] as $terrain) {
                expect_true(($terrainCounts[$terrain] ?? 0) > 0, "{$terrain} should occupy a corner region");
            }

            $board = Board::fromScenario($scenario);
            expect_same([1 => '5,0', 2 => '-1,12'], Board::homes($board), 'starts should be centered on the left and right edges');
            foreach (Board::homes($board) as $home) {
                $hex = $scenario['map']['hexes'][array_search($home, array_map(static fn(array $candidate): string => "{$candidate['q']},{$candidate['r']}", $scenario['map']['hexes']), true)];
                expect_true(!in_array($hex['terrain'], ['open-water', 'ice', 'lava', 'poison-pits'], true), 'starts must be on safe terrain');
            }
            Placement::assertCapacity($board, 7);
        },

        'home map conversion preserves every source hex and adds only the approved homes' => static function (): void {
            $source = json_decode(
                (string)file_get_contents(dirname(__DIR__, 2) . '/data/Home_Map.json'),
                true,
                flags: JSON_THROW_ON_ERROR
            );
            $scenario = built_in_scenario('home-map');

            expect_same('Home Map', $scenario['name'], 'the converted map should have its approved name');
            expect_same(['vanillaPvp'], $scenario['modeCompatibility'], 'the converted map should be Vanilla-only');
            expect_same(32, $scenario['map']['columns'], 'the source width should be preserved');
            expect_same(30, $scenario['map']['rows'], 'the source height should be preserved');
            expect_same(960, count($scenario['map']['hexes']), 'every source hex should be preserved');

            $generated = [];
            $starts = [];
            foreach ($scenario['map']['hexes'] as $hex) {
                $key = "{$hex['q']},{$hex['r']}";
                $generated[$key] = [$hex['q'], $hex['r'], $hex['biome'], $hex['terrain'], $hex['elevation']];
                if ($hex['playerStart'] !== null) {
                    $starts[(string)$hex['playerStart']] = $key;
                }
            }
            foreach ($source['hexes'] as $hex) {
                $q = (int)$hex['row'] - intdiv((int)$hex['col'], 2);
                $r = (int)$hex['col'];
                $key = "{$q},{$r}";
                expect_same(
                    [$q, $r, $hex['biome'], $hex['terrain'], (int)$hex['height']],
                    $generated[$key] ?? null,
                    "source terrain {$key} should be unchanged"
                );
            }
            expect_same(['1' => '2,3', '2' => '12,28'], $starts, 'only the approved homes should be added');
            Placement::assertCapacity(Board::fromScenario($scenario), 7);
        },

        'rectangular built in maps use one canonical axial orientation' => static function (): void {
            expect_canonical_rectangle(built_in_scenario('proving-field'), 'Proving Field');
            expect_canonical_rectangle(built_in_scenario('four-corners'), 'Four Corners');
            expect_canonical_rectangle(built_in_scenario('home-map'), 'Home Map');
        },

        'large built in maps remain connected' => static function (): void {
            expect_connected_scenario(built_in_scenario('four-corners'), 'Four Corners should have one traversable component');
            expect_connected_scenario(built_in_scenario('home-map'), 'Home Map should have one traversable component');
        },
    ];
}
