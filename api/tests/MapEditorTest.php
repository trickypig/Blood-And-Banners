<?php
declare(strict_types=1);

use BloodAndBanners\Http\Request;

function editor_definition(): array
{
    return (new BloodAndBanners\Scenario\LegacyMapImporter())->import([
        'name' => 'My unfinished map', 'columns' => 1, 'rows' => 1,
        'hexes' => [['q' => 0, 'r' => 0, 'biome' => 'plains', 'terrain' => 'grass', 'height' => 2]],
    ]);
}

function map_editor_tests(): array
{
    return [
        'HTTP saved map roundtrip preserves empty object metadata' => static function (): void {
            $ctx = account_context();
            $owner = sign_in($ctx, 'shape@example.test');
            $definition = editor_definition();
            $definition['customScenario'] = (object)[];
            $definition['customNumericObject'] = (object)['0' => 'zero'];
            $definition['placements'] = [(object)['options' => (object)[]]];
            $json = json_encode(['definition' => $definition], JSON_THROW_ON_ERROR);
            $request = BloodAndBanners\Http\RequestFactory::fromHttp('POST', '/api/v1/account/maps', bearer($owner), $json, []);
            $created = $ctx['app']->handle($request);
            expect_same(201, $created->status, 'saved raw JSON');
            $loaded = $ctx['app']->handle(new Request('GET', '/api/v1/account/maps/' . $created->body['summary']['scenarioId'], bearer($owner)));
            $wire = json_decode(json_encode($loaded->body, JSON_THROW_ON_ERROR));
            expect_true(is_object($wire->definition->customScenario), 'empty custom object remains object');
            expect_true(is_object($wire->definition->customNumericObject), 'numeric object keys remain object');
            expect_true(is_object($wire->definition->placements[0]->options), 'nested empty object remains object');
        },
        'competing editor connections accept one revision and conflict the other' => static function (): void {
            $path = tempnam(sys_get_temp_dir(), 'bb-map-race-');
            $ready = [$path . '-ready-1', $path . '-ready-2'];
            $processes = [];
            $db = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            try {
                (new BloodAndBanners\Database\Migrator($db))->migrate();
                $db->exec("INSERT INTO users (id,email,password_hash,display_name,created_at) VALUES ('owner','race@example.test','','Owner','2026-10-21 00:00:00')");
                $service = new BloodAndBanners\Scenario\ScenarioService($db);
                $saved = $service->createOwned('owner', editor_definition())['summary'];
                $db->beginTransaction();
                $db->exec('UPDATE scenarios SET name = name');
                foreach ($ready as $signal) {
                    $pipes = [];
                    $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/map-save-worker.php', $path, 'owner', $saved['scenarioId'], $saved['revisionId'], $signal],
                        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                    expect_true(is_resource($process), 'worker starts');
                    fclose($pipes[0]);
                    $processes[] = [$process, $pipes];
                }
                $deadline = microtime(true) + 5;
                while ((!file_exists($ready[0]) || !file_exists($ready[1])) && microtime(true) < $deadline) { usleep(10000); }
                expect_true(file_exists($ready[0]) && file_exists($ready[1]), 'both writers reach locked map');
                $db->rollBack();
                $statuses = [];
                foreach ($processes as [$process, $pipes]) {
                    $statuses[] = stream_get_contents($pipes[1]);
                    $errors = stream_get_contents($pipes[2]);
                    fclose($pipes[1]); fclose($pipes[2]);
                    expect_same(0, proc_close($process), 'worker succeeds: ' . $errors);
                }
                $processes = [];
                sort($statuses);
                expect_same(['200', '409'], $statuses, 'one save wins and one conflicts');
                expect_same(2, (int)$db->query('SELECT COUNT(*) FROM scenario_revisions')->fetchColumn(), 'only one new revision');
            } finally {
                if ($db->inTransaction()) $db->rollBack();
                foreach ($processes as [$process, $pipes]) { proc_terminate($process); foreach ($pipes as $pipe) { if (is_resource($pipe)) fclose($pipe); } proc_close($process); }
                $db = null; $service = null;
                foreach (array_merge([$path, $path . '-journal', $path . '-wal', $path . '-shm'], $ready) as $file) { if (is_file($file)) unlink($file); }
            }
        },
        'editor routes require accounts including reads' => static function (): void {
            $ctx = account_context();
            $guest = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'editor-guest']))->body;
            foreach ([['GET', ''], ['POST', ''], ['GET', '/missing'], ['PUT', '/missing']] as [$method, $suffix]) {
                expect_same(401, $ctx['app']->handle(new Request($method, '/api/v1/account/maps' . $suffix))->status, 'anonymous denied');
                expect_same(403, $ctx['app']->handle(new Request($method, '/api/v1/account/maps' . $suffix, bearer($guest)))->status, 'guest denied');
            }
        },
        'saved maps isolate owners and keep immutable revisions' => static function (): void {
            $ctx = account_context();
            $owner = sign_in($ctx, 'maps@example.test');
            $other = sign_in($ctx, 'other@example.test');
            $definition = editor_definition();
            $create = $ctx['app']->handle(new Request('POST', '/api/v1/account/maps', bearer($owner), ['definition' => $definition]));
            expect_same(201, $create->status, 'incomplete map saved');
            $first = $create->body['summary'];
            $path = '/api/v1/account/maps/' . $first['scenarioId'];
            expect_same(1, $first['revision'], 'first revision');
            expect_same($definition, $ctx['app']->handle(new Request('GET', $path, bearer($owner)))->body['definition'], 'owner loads');
            expect_same([], $ctx['app']->handle(new Request('GET', '/api/v1/account/maps', bearer($other)))->body['maps'], 'other list empty');
            foreach (['GET', 'PUT'] as $method) {
                $foreign = $ctx['app']->handle(new Request($method, $path, bearer($other), ['definition' => $definition, 'expectedRevisionId' => $first['revisionId']]));
                $missing = $ctx['app']->handle(new Request($method, '/api/v1/account/maps/missing', bearer($other), ['definition' => $definition, 'expectedRevisionId' => $first['revisionId']]));
                expect_same(404, $foreign->status, 'other owner denied');
                expect_same($missing->body, $foreign->body, 'IDs disclose nothing');
            }
            $definition['name'] = 'Renamed';
            $updated = $ctx['app']->handle(new Request('PUT', $path, bearer($owner), ['definition' => $definition, 'expectedRevisionId' => $first['revisionId']]));
            expect_same(200, $updated->status, 'save accepted');
            expect_same(2, $updated->body['summary']['revision'], 'second revision');
            $original = (new BloodAndBanners\Scenario\ScenarioService($ctx['db']))->getRevision($first['revisionId']);
            expect_same('My unfinished map', $original['name'], 'old revision immutable');
            $stale = $ctx['app']->handle(new Request('PUT', $path, bearer($owner), ['definition' => $definition, 'expectedRevisionId' => $first['revisionId']]));
            expect_same(409, $stale->status, 'stale save conflicts');
            expect_same('mapConflict', $stale->body['error']['code'], 'typed conflict');
            expect_same(2, (int)$ctx['db']->query('SELECT COUNT(*) FROM scenario_revisions')->fetchColumn(), 'no extra stale revision');
            $duplicate = $ctx['app']->handle(new Request('POST', '/api/v1/account/maps', bearer($owner), ['definition' => $definition]));
            expect_same(201, $duplicate->status, 'duplicate names allowed');
            expect_same(2, count($ctx['app']->handle(new Request('GET', '/api/v1/account/maps', bearer($owner)))->body['maps']), 'both listed');
        },
        'editor rejects malformed maps without storing them' => static function (): void {
            $ctx = account_context();
            $owner = sign_in($ctx, 'validation@example.test');
            $valid = editor_definition();
            $bad = [];
            $d = $valid; $d['map']['columns'] = '1'; $bad[] = $d;
            $d = $valid; $d['map']['hexes'][] = $d['map']['hexes'][0]; $bad[] = $d;
            $d = $valid; $d['map']['hexes'][0]['terrain'] = 'lava'; $bad[] = $d;
            $d = $valid; $d['map']['hexes'][0]['playerStart'] = 5; $bad[] = $d;
            $d = $valid; $d['name'] = ' '; $bad[] = $d;
            $d = $valid; $d['modeCompatibility'] = ['unknown']; $bad[] = $d;
            $d = $valid; $d['map']['hexes'][0]['elevation'] = '2'; $bad[] = $d;
            $d = $valid; $d['map']['hexes'][0]['portal'] = 'false'; $bad[] = $d;
            foreach ($bad as $index => $definition) {
                expect_same(400, $ctx['app']->handle(new Request('POST', '/api/v1/account/maps', bearer($owner), ['definition' => $definition]))->status, "invalid case {$index}");
            }
            expect_same(400, $ctx['app']->handle(new Request('POST', '/api/v1/account/maps', bearer($owner)))->status, 'missing definition');
            expect_same(400, $ctx['app']->handle(BloodAndBanners\Http\RequestFactory::fromHttp('POST', '/api/v1/account/maps', bearer($owner), '', []))->status, 'empty HTTP body');
            $valid['extra'] = str_repeat('x', 2097152);
            expect_same(413, $ctx['app']->handle(new Request('POST', '/api/v1/account/maps', bearer($owner), ['definition' => $valid]))->status, 'bounded size');
            expect_same(0, (int)$ctx['db']->query('SELECT COUNT(*) FROM scenarios')->fetchColumn(), 'invalid maps not written');
        },
        'rune radius must be 1 to 4 when present' => static function (): void {
            $definition = editor_definition();
            $definition['map']['hexes'][0]['object'] = 'rune-of-strength';
            $definition['map']['hexes'][0]['radius'] = 4;
            expect_same(4, \BloodAndBanners\Scenario\MapValidator::validate($definition)['map']['hexes'][0]['radius'], 'radius 4 accepted');
            $definition['map']['hexes'][0]['radius'] = null;
            \BloodAndBanners\Scenario\MapValidator::validate($definition);
            foreach ([0, 5, 'big'] as $bad) {
                $definition['map']['hexes'][0]['radius'] = $bad;
                try {
                    \BloodAndBanners\Scenario\MapValidator::validate($definition);
                    throw new RuntimeException("radius {$bad} was accepted");
                } catch (\BloodAndBanners\Scenario\MapException) {
                }
            }
        },
    ];
}
