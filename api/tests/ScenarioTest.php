<?php
declare(strict_types=1);

use BloodAndBanners\Database\ConnectionFactory;
use BloodAndBanners\Database\Migrator;
use BloodAndBanners\Scenario\LegacyMapImporter;
use BloodAndBanners\Scenario\ScenarioService;

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
            expect_same(3, count($files), 'three curated scenarios should ship');
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
    ];
}
