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

function scenario_tests(): array
{
    return [
        'legacy editor maps normalize into the versioned scenario schema' => static function (): void {
            $legacy = [
                'name' => 'Old Crossing',
                'columns' => 2,
                'rows' => 1,
                'hexes' => [
                    ['col' => 0, 'row' => 0, 'terrain' => 'grass', 'height' => 2, 'playerStart' => 1],
                    ['col' => 1, 'row' => 0, 'terrain' => 'lava', 'object' => 'fountain'],
                ],
            ];

            $scenario = (new LegacyMapImporter())->import($legacy);

            expect_same(1, $scenario['schemaVersion'], 'scenario schema version should be explicit');
            expect_same('plains', $scenario['map']['hexes'][0]['biome'], 'grass should map to the plains biome');
            expect_same(2, $scenario['map']['hexes'][0]['elevation'], 'legacy height should become elevation');
            expect_same(1, $scenario['map']['hexes'][0]['playerStart'], 'player starts should be retained');
            expect_same(['vanillaPvp', 'coop', 'battleRoyale'], $scenario['modeCompatibility'], 'legacy maps should remain playable in every mode');
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

        'built in scenarios cover every game mode with valid maps' => static function (): void {
            $files = glob(dirname(__DIR__) . '/scenarios/*.json') ?: [];
            expect_same(6, count($files), 'six curated scenarios should ship');
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
            expect_same([1 => '0,5', 2 => '12,5'], Board::homes($board), 'starts should be centered on the left and right edges');
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
                $key = "{$hex['col']},{$hex['row']}";
                expect_same(
                    [(int)$hex['col'], (int)$hex['row'], $hex['biome'], $hex['terrain'], (int)$hex['height']],
                    $generated[$key] ?? null,
                    "source terrain {$key} should be unchanged"
                );
            }
            expect_same(['1' => '3,3', '2' => '28,26'], $starts, 'only the approved homes should be added');
            Placement::assertCapacity(Board::fromScenario($scenario), 7);
        },

        'large built in maps remain connected' => static function (): void {
            expect_connected_scenario(built_in_scenario('four-corners'), 'Four Corners should have one traversable component');
            expect_connected_scenario(built_in_scenario('home-map'), 'Home Map should have one traversable component');
        },
    ];
}
