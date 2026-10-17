<?php
declare(strict_types=1);

/** One hero, the host as Void Lord with a wolf placed in the lobby, the match started and one hero pick made. */
function coop_hidden_prep(): array
{
    $match = coop_service_match(1);
    vanilla_submit($match, 'host', 'claimVoidLord', []);
    coop_lobby_place($match, 'host', 'Test Wolf');
    coop_ready_all($match);
    $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
    vanilla_submit($match, 'hero1', 'pickHero', ['hero' => 'Test Captain']);
    return $match;
}

function coop_event_kinds(array $page): array
{
    return array_column($page['events'], 'kind');
}

function coop_stale_submit(array $match, string $who, int $version, string $kind, array $payload): array
{
    return $match['games']->submitCommand($match['gameId'], $match[$who]['identity'], $match[$who]['token'], [
        'commandId' => bin2hex(random_bytes(8)), 'expectedVersion' => $version, 'kind' => $kind, 'payload' => $payload,
    ]);
}

function coop_hidden_info_tests(): array
{
    return [
        'simultaneous prep orders tolerate the other side version bump' => static function (): void {
            $match = coop_service_match(1);
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_lobby_place($match, 'host', 'Test Wolf');
            coop_ready_all($match);
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            $before = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            vanilla_submit($match, 'hero1', 'pickHero', ['hero' => 'Test Captain']);
            $spawn = coop_lobby_spawn($before, 'Test Wolf');
            $placed = coop_stale_submit($match, 'host', $before['version'], 'placeMonster', ['monster' => 'Test Wolf', 'hex' => $spawn]);
            expect_same(2, count($placed['units']), 'a stale void lord placement still lands');

            $stale = $match['games']->getGame($match['gameId'], $match['hero1']['identity'])['version'];
            coop_lobby_place($match, 'host', 'Test Wolf');
            $picked = coop_stale_submit($match, 'hero1', $stale, 'pickHero', ['hero' => 'Test Marshal']);
            expect_true($picked['version'] > $stale, 'a stale hero pick still lands');
        },

        'a stale version still conflicts in a vanilla draft' => static function (): void {
            $match = vanilla_service_match();
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            $stale = $match['games']->getGame($match['gameId'], $match['host']['identity'])['version'];
            vanilla_submit($match, 'host', 'chooseFirstPlayer', ['seatId' => $match['host']['seat']]);
            expect_game_error('versionConflict', static fn() => coop_stale_submit($match, 'rival', $stale, 'chooseStartPosition', ['start' => 2]));
        },

        'heroes and spectators cannot see the lobby horde' => static function (): void {
            $match = coop_service_match(1);
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_lobby_place($match, 'host', 'Test Wolf');
            $hero = $match['games']->getGame($match['gameId'], $match['hero1']['identity']);
            expect_same([[], null], [$hero['units'], $hero['setup']], 'the hero sees no monsters or budget');
            $watched = $match['games']->getWatchedGame($match['gameId']);
            expect_same([[], null], [$watched['units'], $watched['setup']], 'nor does a spectator');
        },

        'prep projections show each side only its own choices' => static function (): void {
            $match = coop_hidden_prep();
            $hero = $match['games']->getGame($match['gameId'], $match['hero1']['identity']);
            expect_same([], $hero['units'], 'no monsters for the hero');
            expect_same([true, false], [$hero['setup']['redacted'], $hero['setup']['confirmed']], 'the hero only learns whether the void lord is done');
            expect_same(['Test Captain'], $hero['draft']['heroesBySeat'][$match['hero1']['seat']], 'the hero sees the draft');
            expect_same([1, 3, false], [$hero['draft']['picksMade'], $hero['draft']['picksTotal'], $hero['draft']['redacted']], 'with progress');

            $lord = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            expect_same(['v-m1'], array_column($lord['units'], 'unitId'), 'the void lord sees the horde');
            expect_same(false, $lord['setup']['redacted'], 'and the full setup');
            expect_same([[], true, 1], [$lord['draft']['heroesBySeat'][$match['hero1']['seat']], $lord['draft']['redacted'], $lord['draft']['picksMade']], 'but only the pick count');
            expect_same(null, array_column($lord['draft']['availableHeroes'], 'takenBySeatId', 'name')['Test Captain'], 'claims are blanked');
            expect_same([$match['host']['seat']], array_keys($lord['armies']), 'only the horde emblem is projected');

            $watched = $match['games']->getWatchedGame($match['gameId']);
            expect_same([[], true, true], [$watched['units'], $watched['setup']['redacted'], $watched['draft']['redacted']], 'spectators see neither side');
        },

        'no projection field leaks a monster id to a hero or spectator' => static function (): void {
            $match = coop_hidden_prep();
            $hero = json_encode($match['games']->getGame($match['gameId'], $match['hero1']['identity']), JSON_THROW_ON_ERROR);
            $watched = json_encode($match['games']->getWatchedGame($match['gameId']), JSON_THROW_ON_ERROR);
            expect_true(!str_contains($hero, 'v-m1'), 'the hero projection never names the wolf');
            expect_true(!str_contains($watched, 'v-m1'), 'the spectator projection never names the wolf');
        },

        'a monster placed during prep stays hidden from heroes' => static function (): void {
            $match = coop_hidden_prep();
            coop_lobby_place($match, 'host', 'Test Wolf');
            $hero = $match['games']->getGame($match['gameId'], $match['hero1']['identity']);
            expect_same([], $hero['units'], 'the hero sees no units');
            expect_true(!str_contains(json_encode($hero, JSON_THROW_ON_ERROR), 'v-m2'), 'the hero projection never names the second wolf');
            $heroEvents = coop_event_kinds($match['games']->getEvents($match['gameId'], $match['hero1']['identity'], $match['hero1']['token'], 0));
            $lordEvents = coop_event_kinds($match['games']->getEvents($match['gameId'], $match['host']['identity'], $match['host']['token'], 0));
            expect_true(!in_array('monsterPlaced', $heroEvents, true), 'no placement events for heroes');
            expect_same(2, count(array_keys($lordEvents, 'monsterPlaced', true)), 'the void lord sees both placements');
        },

        'events stay with their side until the battle' => static function (): void {
            $match = coop_hidden_prep();
            $heroEvents = coop_event_kinds($match['games']->getEvents($match['gameId'], $match['hero1']['identity'], $match['hero1']['token'], 0));
            $lordEvents = coop_event_kinds($match['games']->getEvents($match['gameId'], $match['host']['identity'], $match['host']['token'], 0));
            $watchedEvents = coop_event_kinds($match['games']->getWatchedEvents($match['gameId'], 0));
            expect_true(!in_array('monsterPlaced', $heroEvents, true) && in_array('draftPicked', $heroEvents, true), 'heroes see picks, not placements');
            expect_true(in_array('monsterPlaced', $lordEvents, true) && !in_array('draftPicked', $lordEvents, true), 'the void lord sees placements, not picks');
            expect_true(!in_array('monsterPlaced', $watchedEvents, true) && !in_array('draftPicked', $watchedEvents, true), 'spectators see neither');

            vanilla_submit($match, 'host', 'confirmSetup', []);
            $heroPage = $match['games']->getEvents($match['gameId'], $match['hero1']['identity'], $match['hero1']['token'], 0);
            expect_true(in_array('setupConfirmed', coop_event_kinds($heroPage), true), 'the confirm itself is public');
            foreach ([$heroPage, $match['games']->getWatchedEvents($match['gameId'], 0)] as $page) {
                $confirm = array_values(array_filter($page['events'], static fn(array $event): bool => $event['kind'] === 'setupConfirmed'));
                expect_same([[]], array_column($confirm, 'payload'), 'the confirm carries no monster count');
            }
            vanilla_submit($match, 'hero1', 'pickHero', ['hero' => 'Test Marshal']);
            vanilla_submit($match, 'hero1', 'pickArmy', ['army' => 'Red Company']);
            foreach ([
                coop_event_kinds($match['games']->getEvents($match['gameId'], $match['hero1']['identity'], $match['hero1']['token'], 0)),
                coop_event_kinds($match['games']->getEvents($match['gameId'], $match['host']['identity'], $match['host']['token'], 0)),
                coop_event_kinds($match['games']->getWatchedEvents($match['gameId'], 0)),
            ] as $kinds) {
                expect_true(in_array('monsterPlaced', $kinds, true) && in_array('draftPicked', $kinds, true) && in_array('armiesPlaced', $kinds, true), 'the battle reveals everything');
            }
            $active = $match['games']->getGame($match['gameId'], $match['hero1']['identity']);
            expect_true(in_array('v-m1', array_column($active['units'], 'unitId'), true), 'the hero sees the horde in battle');
        },
    ];
}
