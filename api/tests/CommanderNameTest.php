<?php
declare(strict_types=1);

function commander_names(array $projection): array
{
    return array_column($projection['memberships'], 'name', 'seatId');
}

function commander_name_tests(): array
{
    return [
        'a commander names their seat in the lobby and every viewer sees it' => static function (): void {
            $match = vanilla_service_match();
            $before = $match['games']->getGame($match['gameId'], $match['host']['identity']);

            $named = vanilla_submit($match, 'host', 'setCommanderName', ['name' => "  Lord \t  Ash\u{0007}fang "]);

            expect_same($before['version'] + 1, $named['version'], 'naming bumps the version');
            expect_same('Lord Ashfang', commander_names($named)[$match['host']['seat']], 'whitespace collapses and control characters vanish');
            expect_same(null, commander_names($named)[$match['rival']['seat']], 'only the caller seat changes');
            expect_same('Lord Ashfang', commander_names($match['games']->getGame($match['gameId'], $match['rival']['identity']))[$match['host']['seat']], 'the rival sees the name');
            $link = $match['games']->createSpectatorLink($match['gameId'], $match['host']['identity'], $match['host']['token']);
            expect_same('Lord Ashfang', commander_names($match['games']->getSpectatorGame($link['token']))[$match['host']['seat']], 'spectators see the name');
            $events = $match['games']->getEvents($match['gameId'], $match['host']['identity'], $match['host']['token'], 0)['events'];
            expect_same(false, in_array('setCommanderName', array_column($events, 'kind'), true), 'naming writes no event');
        },

        'an empty name clears the seat back to its default label' => static function (): void {
            $match = vanilla_service_match();
            vanilla_submit($match, 'host', 'setCommanderName', ['name' => 'Ashfang']);

            $cleared = vanilla_submit($match, 'host', 'setCommanderName', ['name' => "   \n "]);

            expect_same(null, commander_names($cleared)[$match['host']['seat']], 'blank input clears the name');
        },

        'names are limited to 24 characters, counted as characters' => static function (): void {
            $match = vanilla_service_match();
            expect_game_error('nameTooLong', static fn() => vanilla_submit($match, 'host', 'setCommanderName', ['name' => str_repeat('a', 25)]));

            $accented = vanilla_submit($match, 'host', 'setCommanderName', ['name' => str_repeat('É', 24)]);

            expect_same(str_repeat('É', 24), commander_names($accented)[$match['host']['seat']], '24 multibyte characters fit');
        },

        'malformed names are refused without touching the seat' => static function (): void {
            $match = vanilla_service_match();
            expect_game_error('unsupportedCommand', static fn() => vanilla_submit($match, 'host', 'setCommanderName', ['name' => 5]));
            expect_game_error('unsupportedCommand', static fn() => vanilla_submit($match, 'host', 'setCommanderName', []));
            expect_game_error('unsupportedCommand', static fn() => vanilla_submit($match, 'host', 'setCommanderName', ['name' => "Ash\xC3\x28"]));
            expect_same(null, commander_names($match['games']->getGame($match['gameId'], $match['host']['identity']))[$match['host']['seat']], 'refusals leave the name unset');
        },

        'two seats cannot share a name in any letter case' => static function (): void {
            $match = vanilla_service_match();
            vanilla_submit($match, 'host', 'setCommanderName', ['name' => 'Lord Ashfang']);

            expect_game_error('nameTaken', static fn() => vanilla_submit($match, 'rival', 'setCommanderName', ['name' => 'lord ASHFANG']));
            $resaved = vanilla_submit($match, 'host', 'setCommanderName', ['name' => 'Lord Ashfang']);

            expect_same('Lord Ashfang', commander_names($resaved)[$match['host']['seat']], 're-saving your own name is allowed');
        },

        'invisible format characters cannot disguise a taken name' => static function (): void {
            $match = vanilla_service_match();
            vanilla_submit($match, 'host', 'setCommanderName', ['name' => 'Ash']);

            expect_game_error('nameTaken', static fn() => vanilla_submit($match, 'rival', 'setCommanderName', ['name' => "A\u{200B}sh\u{202E}"]));
            $named = vanilla_submit($match, 'rival', 'setCommanderName', ['name' => "Iron\u{2066} Vow\u{FEFF}"]);

            expect_same('Iron Vow', commander_names($named)[$match['rival']['seat']], 'format characters are stripped before storing');
        },

        'names stay editable through the draft and lock when battle begins' => static function (): void {
            $match = vanilla_service_match();
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);

            $rivalDraft = $match['games']->getGame($match['gameId'], $match['rival']['identity']);
            expect_same(['setCommanderName'], array_column($rivalDraft['legalActions'], 'kind'), 'a waiting drafter may still rename');
            $named = vanilla_submit($match, 'rival', 'setCommanderName', ['name' => 'Iron Vow']);
            expect_same('Iron Vow', commander_names($named)[$match['rival']['seat']], 'renaming works during the draft');

            foreach (vanilla_draft_picks($match) as [$who, $kind, $payload]) {
                vanilla_submit($match, $who, $kind, $payload);
            }
            $active = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            expect_same('active', $active['lifecycle'], 'the battle has begun');
            expect_same(false, in_array('setCommanderName', array_column($active['legalActions'], 'kind'), true), 'renaming is no longer offered');
            expect_game_error('illegalLifecycle', static fn() => vanilla_submit($match, 'host', 'setCommanderName', ['name' => 'Too Late']));
            expect_same('Iron Vow', commander_names($active)[$match['rival']['seat']], 'the drafted name carries into battle');
        },

        'co-op hero players can rename while the void lord sets up' => static function (): void {
            $match = coop_service_match();
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_ready_all($match);
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);

            $hero = $match['games']->getGame($match['gameId'], $match['hero1']['identity']);
            expect_same(['pickHero', 'pickArmy', 'setCommanderName'], array_column($hero['legalActions'], 'kind'), 'the hero may draft or rename during preparation');
            $named = vanilla_submit($match, 'hero1', 'setCommanderName', ['name' => 'Seris']);

            expect_same('Seris', commander_names($named)[$match['hero1']['seat']], 'renaming works during setup');
        },

        'a retried rename returns the cached projection' => static function (): void {
            $match = vanilla_service_match();
            $first = vanilla_submit($match, 'host', 'setCommanderName', ['name' => 'Ashfang'], 'rename-once');

            $replayed = $match['games']->submitCommand($match['gameId'], $match['host']['identity'], $match['host']['token'], [
                'commandId' => 'rename-once', 'expectedVersion' => $first['version'] - 1, 'kind' => 'setCommanderName', 'payload' => ['name' => 'Ashfang'],
            ]);

            expect_same($first['version'], $replayed['version'], 'the retry is idempotent');
        },
    ];
}
