<?php
declare(strict_types=1);

use BloodAndBanners\Game\GameService;
use BloodAndBanners\Rules\Vanilla\MatchEngine;

/** A Void Reaches lobby on the real catalog with an injected, settable clock; the host starts as the Void Lord. */
function ai_seat_match(int $heroPlayers = 1, ?int &$clock = null): array
{
    $clock ??= 1000;
    [$db, $auth] = test_services();
    $games = new GameService($db, null, new ScriptedRandom(), new MatchEngine(ai_catalog()), static function () use (&$clock): int {
        return $clock;
    });
    $host = $auth->createGuest('ai-host-' . bin2hex(random_bytes(4)));
    $created = $games->createGame($host['identityId'], 'coop', 'Void Reaches');
    $match = ['db' => $db, 'auth' => $auth, 'games' => $games, 'gameId' => $created['gameId'],
        'host' => ['identity' => $host['identityId'], 'token' => $created['seatToken'], 'seat' => $created['viewer']['seatId']]];
    for ($i = 1; $i <= $heroPlayers; $i++) {
        $guest = $auth->createGuest("ai-guest-{$i}-" . bin2hex(random_bytes(4)));
        $joined = $games->joinGame($created['gameId'], $guest['identityId']);
        $match["hero{$i}"] = ['identity' => $guest['identityId'], 'token' => $joined['seatToken'], 'seat' => $joined['viewer']['seatId']];
    }
    return $match;
}

function ai_seat_view(array $match, string $who = 'host'): array
{
    return $match['games']->getGame($match['gameId'], $match[$who]['identity']);
}

function ai_seat_membership(array $view): ?array
{
    foreach ($view['memberships'] as $seat) {
        if ($seat['aiPersonality'] !== null) {
            return $seat;
        }
    }
    return null;
}

function ai_seat_state(array $match): array
{
    return json_decode((string)$match['db']->query("SELECT state_json FROM game_states WHERE game_id = '{$match['gameId']}'")->fetchColumn(), true);
}

function ai_seat_tests(): array
{
    return [
        'the host seats an ai void lord and becomes a hero' => static function (): void {
            $match = ai_seat_match(0);
            $view = vanilla_submit($match, 'host', 'seatAiVoidLord', ['personality' => 'warden']);
            $ai = ai_seat_membership($view);
            expect_true($ai !== null, 'an AI seat exists');
            expect_same('voidLord', $ai['role'], 'the AI is the Void Lord');
            expect_same('warden', $ai['aiPersonality'], 'its personality');
            expect_same('The Warden', $ai['name'], 'it is named for the personality');
            expect_true($ai['ready'], 'the AI is ready');
            $roles = array_column($view['memberships'], 'role', 'seatId');
            expect_same('player', $roles[$match['host']['seat']], 'the host is a hero');
            expect_same('normal', $view['aiPace'], 'default pace');
            expect_same(4, count($view['aiPersonalities']), 'the catalogue is offered in the lobby');
            expect_true(in_array('seatAiVoidLord', array_column($view['legalActions'], 'kind'), true), 'host may seat an AI');
            expect_true(in_array('setAiPace', array_column($view['legalActions'], 'kind'), true), 'host may set the pace');
            expect_game_error('unknownPersonality', static fn() => vanilla_submit($match, 'host', 'seatAiVoidLord', ['personality' => 'nobody']));
            $changed = vanilla_submit($match, 'host', 'seatAiVoidLord', ['personality' => 'huntress']);
            expect_same(1, count(array_filter($changed['memberships'], static fn(array $s): bool => $s['aiPersonality'] !== null)), 'one AI seat only');
            expect_same('The Huntress', ai_seat_membership($changed)['name'], 'the name follows the personality');
        },
        'only the host seats the ai, only in a lobby, and only with room' => static function (): void {
            $match = ai_seat_match(1);
            expect_game_error('hostRequired', static fn() => vanilla_submit($match, 'hero1', 'seatAiVoidLord', ['personality' => 'warden']));
            vanilla_submit($match, 'host', 'seatAiVoidLord', ['personality' => 'ravager']);
            vanilla_submit($match, 'hero1', 'setReady', ['ready' => true]);
            vanilla_submit($match, 'host', 'setReady', ['ready' => true]);
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            expect_game_error('illegalLifecycle', static fn() => vanilla_submit($match, 'host', 'seatAiVoidLord', ['personality' => 'warden']));
            $full = ai_seat_match(4);
            expect_game_error('gameFull', static fn() => vanilla_submit($full, 'host', 'seatAiVoidLord', ['personality' => 'warden']));
        },
        'one hero can start against the ai' => static function (): void {
            $match = ai_seat_match(0);
            vanilla_submit($match, 'host', 'seatAiVoidLord', ['personality' => 'warden']);
            vanilla_submit($match, 'host', 'setReady', ['ready' => true]);
            $started = $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            expect_same('draft', $started['lifecycle'], 'preparation begins');
            expect_true($started['setup']['redacted'] === true, 'the hero does not see the horde');
            expect_true($started['setup']['confirmed'], 'the horde is confirmed');
            $aiSeat = ai_seat_membership($started)['seatId'];
            $stored = ai_seat_state($match);
            expect_true($stored['setup']['spent'] >= 1, 'the AI spent points');
            expect_same($aiSeat, $stored['voidLordSeatId'], 'the AI seat is the Void Lord');
            $kinds = array_column($match['games']->getWatchedEvents($match['gameId'], 0)['events'], 'kind');
            expect_true(in_array('setupConfirmed', $kinds, true), 'confirmation is public');
            expect_true(!in_array('monsterPlaced', $kinds, true), 'placements stay hidden from spectators');
        },
        'seating the ai discards a human horde' => static function (): void {
            $match = ai_seat_match(1);
            $placed = null;
            foreach (ai_seat_view($match)['legalActions'] as $action) {
                if ($action['kind'] === 'placeMonster') {
                    $placed = ['monster' => $action['monster'], 'hex' => $action['targetHexes'][0]];
                    break;
                }
            }
            expect_true($placed !== null, 'a placement is legal');
            vanilla_submit($match, 'host', 'placeMonster', $placed);
            vanilla_submit($match, 'host', 'seatAiVoidLord', ['personality' => 'ravager']);
            expect_same(['v-portal'], array_keys(ai_seat_state($match)['units']), 'only the portal remains');
        },
        'assigning a human removes the ai' => static function (): void {
            $match = ai_seat_match(1);
            vanilla_submit($match, 'host', 'seatAiVoidLord', ['personality' => 'warden']);
            expect_game_error('voidLordTaken', static fn() => vanilla_submit($match, 'hero1', 'claimVoidLord', []));
            $view = vanilla_submit($match, 'host', 'assignVoidLord', ['seatId' => $match['hero1']['seat']]);
            expect_true(ai_seat_membership($view) === null, 'the AI is gone');
            expect_same('voidLord', array_column($view['memberships'], 'role', 'seatId')[$match['hero1']['seat']], 'hero1 is the Void Lord');
            expect_same(2, count($view['memberships']), 'the roster is the humans again');
        },
        'removing the ai seat frees the role' => static function (): void {
            $match = ai_seat_match(1);
            $view = vanilla_submit($match, 'host', 'seatAiVoidLord', ['personality' => 'warden']);
            $aiSeat = ai_seat_membership($view)['seatId'];
            $after = $match['games']->removeSeat($match['gameId'], $match['host']['identity'], $match['host']['token'], $aiSeat);
            expect_true(ai_seat_membership($after) === null, 'the AI seat is gone');
            expect_true(!in_array('voidLord', array_column($after['memberships'], 'role'), true), 'nobody holds the role');
        },
        'the host cannot reassign the ai seat to a person' => static function (): void {
            $match = ai_seat_match(1);
            $view = vanilla_submit($match, 'host', 'seatAiVoidLord', ['personality' => 'warden']);
            $aiSeat = ai_seat_membership($view)['seatId'];
            $other = $match['auth']->createGuest('ai-takeover-' . bin2hex(random_bytes(4)));
            expect_game_error('seatNotFound', static fn() => $match['games']->reassignSeat(
                $match['gameId'], $match['host']['identity'], $match['host']['token'], $aiSeat, $other['identityId']
            ));
        },
        'ai horde events are credited to the ai seat, not the host' => static function (): void {
            $match = ai_seat_match(0);
            vanilla_submit($match, 'host', 'seatAiVoidLord', ['personality' => 'warden']);
            vanilla_submit($match, 'host', 'setReady', ['ready' => true]);
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            $actors = $match['db']->query("SELECT DISTINCT acting_identity_id FROM game_events WHERE game_id = '{$match['gameId']}' AND kind IN ('setupConfirmed', 'monsterPlaced')")->fetchAll(PDO::FETCH_COLUMN);
            expect_true($actors !== [] && !in_array($match['host']['identity'], $actors, true), 'the host is not credited');
        },
        'only the host changes the pace and only to a known pace' => static function (): void {
            $match = ai_seat_match(1);
            expect_game_error('noAiSeat', static fn() => vanilla_submit($match, 'host', 'setAiPace', ['pace' => 'fast']));
            vanilla_submit($match, 'host', 'seatAiVoidLord', ['personality' => 'warden']);
            expect_game_error('hostRequired', static fn() => vanilla_submit($match, 'hero1', 'setAiPace', ['pace' => 'fast']));
            expect_game_error('invalidPace', static fn() => vanilla_submit($match, 'host', 'setAiPace', ['pace' => 'turbo']));
            vanilla_submit($match, 'hero1', 'setReady', ['ready' => true]);
            vanilla_submit($match, 'host', 'setReady', ['ready' => true]);
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            $stale = ai_seat_view($match)['version'] - 1;
            $view = $match['games']->submitCommand($match['gameId'], $match['host']['identity'], $match['host']['token'], [
                'commandId' => bin2hex(random_bytes(8)), 'expectedVersion' => $stale, 'kind' => 'setAiPace', 'payload' => ['pace' => 'fast'],
            ]);
            expect_same('fast', $view['aiPace'], 'the pace changes during the draft, ignoring a stale version');
            expect_true(in_array('setAiPace', array_column($view['legalActions'], 'kind'), true), 'still offered in the draft');
        },
        'the ai never inherits the host' => static function (): void {
            $match = ai_seat_match(1);
            vanilla_submit($match, 'host', 'seatAiVoidLord', ['personality' => 'warden']);
            $match['games']->leaveGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            $view = ai_seat_view($match, 'hero1');
            expect_true(array_column($view['memberships'], 'isHost', 'seatId')[$match['hero1']['seat']], 'the hero is the host');
            $match['games']->leaveGame($match['gameId'], $match['hero1']['identity'], $match['hero1']['token']);
            expect_same(0, (int)$match['db']->query("SELECT COUNT(*) FROM games WHERE id = '{$match['gameId']}'")->fetchColumn(), 'the room with only the AI is deleted');
        },
        'ai_stepped_at is set at start and on each match command in an ai game' => static function (): void {
            $clock = 5000;
            $match = ai_seat_match(0, $clock);
            vanilla_submit($match, 'host', 'seatAiVoidLord', ['personality' => 'ravager']);
            vanilla_submit($match, 'host', 'setReady', ['ready' => true]);
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            $read = static function () use ($match): ?int {
                $value = $match['db']->query("SELECT ai_stepped_at FROM games WHERE id = '{$match['gameId']}'")->fetchColumn();
                return $value === null ? null : (int)$value;
            };
            expect_same(5000, $read(), 'set at start');
            $clock = 9000;
            $hero = ai_catalog()->heroes()[0]['name'];
            vanilla_submit($match, 'host', 'pickHero', ['hero' => $hero]);
            expect_same(9000, $read(), 'updated by a human match command');
        },
    ];
}
