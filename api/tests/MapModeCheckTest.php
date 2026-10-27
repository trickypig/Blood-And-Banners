<?php
declare(strict_types=1);

use BloodAndBanners\Http\Request;
use BloodAndBanners\Scenario\MapModeCheck;

/** Saves a built-in scenario as the player's own map under a new name; returns the saved summary. */
function save_own_map(array $ctx, array $session, string $file, string $name, ?callable $change = null): array
{
    $definition = built_in_scenario($file);
    $definition['name'] = $name;
    if ($change !== null) {
        $definition = $change($definition);
    }
    $saved = $ctx['app']->handle(new Request('POST', '/api/v1/account/maps', bearer($session), ['definition' => $definition]));
    expect_same(201, $saved->status, 'map saved: ' . json_encode($saved->body));
    return $saved->body['summary'];
}

/** The map with its Void Portal taken away: no longer ready for an incursion. */
function without_portal(array $definition): array
{
    foreach ($definition['map']['hexes'] as $index => $hex) {
        unset($definition['map']['hexes'][$index]['voidPortal']);
    }
    return $definition;
}

function map_mode_check_tests(): array
{
    return [
        // The editor's MapValidation.cs asserts the same table in MapValidationTests, so the two checks agree.
        'the server map check matches the editor on every built-in map' => static function (): void {
            $expected = [
                'four-corners' => [2, 0, 0], 'home-map' => [2, 0, 0], 'proving-field' => [2, 0, 0],
                'training-grounds' => [0, 0, 0], 'last-banner' => [0, 0, 0], 'void-reaches' => [0, 4, 0],
                'incursion-twin-roads' => [0, 4, 0], 'incursion-serpents-coil' => [0, 4, 0], 'incursion-the-gorge' => [0, 4, 0],
                'incursion-lakeheart' => [0, 4, 0], 'incursion-blighted-march' => [0, 4, 0],
            ];
            foreach ($expected as $file => $players) {
                $definition = built_in_scenario($file);
                $actual = array_map(static fn(string $mode): int => MapModeCheck::players($definition, $mode), ['vanillaPvp', 'coop', 'battleRoyale']);
                expect_same($players, $actual, "{$file} seats the same players as the editor says");
            }
        },

        'the check explains why a map is not ready' => static function (): void {
            $check = MapModeCheck::check(without_portal(built_in_scenario('void-reaches')), 'coop');
            expect_same(0, $check['players'], 'no portal, no incursion');
            expect_same(['Place the Void Portal for the Void Lord.'], $check['issues'], 'the missing portal is named');
        },

        'players see each own map at its newest revision that is ready for the mode' => static function (): void {
            $ctx = account_context();
            $owner = sign_in($ctx, 'maker@example.test');
            $isle = save_own_map($ctx, $owner, 'incursion-twin-roads', 'My Isle');
            $updated = $ctx['app']->handle(new Request('PUT', '/api/v1/account/maps/' . $isle['scenarioId'], bearer($owner), [
                'definition' => without_portal(array_replace(built_in_scenario('incursion-twin-roads'), ['name' => 'My Isle (unfinished)'])),
                'expectedRevisionId' => $isle['revisionId'],
            ]));
            expect_same(200, $updated->status, 'an unfinished revision saves');
            // A map that claims to suit Co-op but has no room is not offered: the server runs its own check.
            save_own_map($ctx, $owner, 'last-banner', 'Cramped', static fn(array $d): array => ['modeCompatibility' => ['coop']] + $d);
            $duel = save_own_map($ctx, $owner, 'four-corners', 'My Duel');

            $coop = $ctx['app']->handle(new Request('GET', '/api/v1/account/maps/playable/coop', bearer($owner)));
            expect_same(200, $coop->status, 'the list loads');
            expect_same([[
                'scenarioId' => $isle['scenarioId'], 'name' => 'My Isle', 'revisionId' => $isle['revisionId'],
                'revision' => 1, 'isLatest' => false, 'players' => 4,
            ]], array_map(static fn(array $m): array => array_diff_key($m, ['updatedAt' => true]), $coop->body['maps']), 'the ready revision is offered, not the newer unfinished one');
            $vanilla = $ctx['app']->handle(new Request('GET', '/api/v1/account/maps/playable/vanillaPvp', bearer($owner)));
            expect_same([[$duel['revisionId'], true, 2]], array_map(static fn(array $m): array => [$m['revisionId'], $m['isLatest'], $m['players']], $vanilla->body['maps']), 'duel maps are listed for duels');

            $board = $ctx['app']->handle(new Request('GET', "/api/v1/account/maps/revisions/{$isle['revisionId']}/board", bearer($owner)));
            expect_same([200, 631], [$board->status, count($board->body['hexes'])], 'the owner can preview the revision');
            $stranger = sign_in($ctx, 'stranger@example.test');
            $peek = $ctx['app']->handle(new Request('GET', "/api/v1/account/maps/revisions/{$isle['revisionId']}/board", bearer($stranger)));
            expect_same(404, $peek->status, 'nobody else can preview it');
            $guest = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'guest-device']))->body;
            expect_same(403, $ctx['app']->handle(new Request('GET', '/api/v1/account/maps/playable/coop', bearer($guest)))->status, 'guests have no maps');
        },

        'a war room on a smaller own map seats only the players it holds' => static function (): void {
            $ctx = account_context();
            $owner = sign_in($ctx, 'small-maker@example.test');
            // Rocks on every hex three steps from the start leave room for 18 units: two hero players.
            $walled = static function (array $definition): array {
                foreach ($definition['map']['hexes'] as $index => $hex) {
                    $dq = $hex['q'] - 18;
                    $dr = $hex['r'] - 14;
                    if (intdiv(abs($dq) + abs($dr) + abs($dq + $dr), 2) === 3) {
                        $definition['map']['hexes'][$index] = ['object' => 'rock', 'sightBlocker' => true, 'spawnZone' => null] + $hex;
                    }
                }
                return $definition;
            };
            $small = save_own_map($ctx, $owner, 'incursion-twin-roads', 'Small Isle', $walled);
            $listed = $ctx['app']->handle(new Request('GET', '/api/v1/account/maps/playable/coop', bearer($owner)))->body['maps'];
            expect_same(2, $listed[0]['players'], 'the walled map holds two hero players');

            $created = $ctx['app']->handle(new Request('POST', '/api/v1/games', bearer($owner), ['mode' => 'coop', 'scenarioName' => '', 'scenarioRevisionId' => $small['revisionId']]));
            expect_same(3, $created->body['game']['seatLimit'], 'two hero seats and the Void Lord');
            $gameId = $created->body['game']['gameId'];
            foreach (['second', 'third'] as $who) {
                $guest = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => "{$who}-device"]))->body;
                expect_same(200, $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/join", bearer($guest)))->status, "the {$who} seat is open");
            }
            $late = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'late-device']))->body;
            $refused = $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/join", bearer($late)));
            expect_same([422, 'gameFull'], [$refused->status, $refused->body['error']['code']], 'a fourth player is turned away');

            $builtIn = create_game($ctx, $owner, 'coop', 'Void Reaches');
            expect_same(5, $builtIn['game']['seatLimit'], 'built-in maps keep the usual seats');
        },

        'a war room opens on the host own ready map revision and nobody else can use it' => static function (): void {
            $ctx = account_context();
            $owner = sign_in($ctx, 'host-maker@example.test');
            $isle = save_own_map($ctx, $owner, 'incursion-twin-roads', 'My Isle');
            $open = static fn(array $session, string $mode, string $revisionId) => $ctx['app']->handle(new Request('POST', '/api/v1/games', bearer($session),
                ['mode' => $mode, 'scenarioName' => 'ignored', 'scenarioRevisionId' => $revisionId]));

            $created = $open($owner, 'coop', $isle['revisionId']);
            expect_same(201, $created->status, 'the war room opens: ' . json_encode($created->body));
            expect_same('My Isle', $created->body['game']['scenarioName'], 'it is named after the map');
            $revision = $ctx['db']->query('SELECT scenario_revision_id FROM games')->fetchColumn();
            expect_same($isle['revisionId'], $revision, 'the game keeps that exact revision');

            $wrongMode = $open($owner, 'vanillaPvp', $isle['revisionId']);
            expect_same([422, 'scenarioModeIncompatible'], [$wrongMode->status, $wrongMode->body['error']['code']], 'a map not ready for the mode is refused');
            $stranger = $open(sign_in($ctx, 'thief@example.test'), 'coop', $isle['revisionId']);
            expect_same([404, 'scenarioNotFound'], [$stranger->status, $stranger->body['error']['code']], 'another player cannot use it');
            $guest = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'guest-maker']))->body;
            expect_same(403, $open($guest, 'coop', $isle['revisionId'])->status, 'guests must sign in');
        },
    ];
}
