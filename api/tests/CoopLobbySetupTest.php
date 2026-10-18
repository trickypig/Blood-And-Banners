<?php
declare(strict_types=1);

/** The first legal spawn hex for $monster in a projection's legal actions. */
function coop_lobby_spawn(array $projection, string $monster): string
{
    foreach ($projection['legalActions'] as $action) {
        if ($action['kind'] === 'placeMonster' && ($action['monster'] ?? null) === $monster) {
            return $action['targetHexes'][0];
        }
    }
    throw new RuntimeException("{$monster} has nowhere to go");
}

function coop_lobby_place(array $match, string $who, string $monster): array
{
    $view = $match['games']->getGame($match['gameId'], $match[$who]['identity']);
    return vanilla_submit($match, $who, 'placeMonster', ['monster' => $monster, 'hex' => coop_lobby_spawn($view, $monster)]);
}

/** A hex the wolf could take in $before that is still empty in $after. */
function coop_lobby_free_wolf_hex(array $before, array $after): string
{
    $taken = array_column($after['units'], 'hex');
    return array_values(array_diff(coop_lobby_wolf_hexes($before), $taken))[0];
}

function coop_lobby_wolf_hexes(array $projection): array
{
    foreach ($projection['legalActions'] as $action) {
        if ($action['kind'] === 'placeMonster' && ($action['monster'] ?? null) === 'Test Wolf') {
            return $action['targetHexes'];
        }
    }
    return [];
}

function coop_lobby_state_count(array $match): int
{
    $statement = $match['db']->prepare('SELECT COUNT(*) FROM game_states WHERE game_id = :game');
    $statement->execute(['game' => $match['gameId']]);
    return (int)$statement->fetchColumn();
}

function coop_lobby_setup_tests(): array
{
    return [
        'the void lord places monsters in the war room on a live budget' => static function (): void {
            $match = coop_service_match(2);
            $claimed = vanilla_submit($match, 'host', 'claimVoidLord', []);
            expect_same(20, $claimed['setup']['budget'], 'two hero players: 4 + 8 × 2');
            expect_same('lobby', $claimed['lifecycle'], 'the war room stays open');
            $placed = coop_lobby_place($match, 'host', 'Test Wolf');
            expect_same(['v-m1'], array_column($placed['units'], 'unitId'), 'the wolf is on the board');
            expect_same(1, $placed['setup']['spent'], 'its cost is spent');
            expect_true(!in_array('confirmSetup', array_column($placed['legalActions'], 'kind'), true), 'confirm waits for the start');
            expect_game_error('rosterNotLocked', static fn() => vanilla_submit($match, 'host', 'confirmSetup', []));
            expect_game_error('notVoidLord', static fn() => vanilla_submit($match, 'hero1', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => $placed['setup']['spawnZoneHexes'][5]]));
        },

        'leaves shrink the lobby budget and joins grow it, without moving monsters' => static function (): void {
            $match = coop_service_match(1);
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            foreach (range(1, 3) as $ignored) {
                coop_lobby_place($match, 'host', 'Test Wyrm');
            }
            $match['games']->leaveGame($match['gameId'], $match['hero1']['identity'], $match['hero1']['token']);
            $after = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            expect_same([4, 12, 3], [$after['setup']['budget'], $after['setup']['spent'], count($after['units'])], 'over budget, monsters stay');
            expect_true(in_array('placeMonster', array_column($after['legalActions'], 'kind'), true), 'the war room still lets the void lord run ahead of the budget');
            $guest = $match['auth']->createGuest('coop-late-' . bin2hex(random_bytes(4)));
            $match['games']->joinGame($match['gameId'], $guest['identityId']);
            expect_same(12, $match['games']->getGame($match['gameId'], $match['host']['identity'])['setup']['budget'], 'a join grows it back');
        },

        'reassigning the void lord hands over the monsters; releasing clears them' => static function (): void {
            $match = coop_service_match(1);
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_lobby_place($match, 'host', 'Test Wolf');
            vanilla_submit($match, 'host', 'assignVoidLord', ['seatId' => $match['hero1']['seat']]);
            $heir = $match['games']->getGame($match['gameId'], $match['hero1']['identity']);
            expect_same([$match['hero1']['seat']], array_column($heir['units'], 'seatId'), 'the wolf follows the role');
            expect_true(in_array('removeMonster', array_column($heir['legalActions'], 'kind'), true), 'the new void lord commands it');
            $old = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            expect_same([[], null], [$old['units'], $old['setup']], 'the old void lord no longer sees it');
            vanilla_submit($match, 'hero1', 'releaseVoidLord', []);
            expect_same(0, coop_lobby_state_count($match), 'releasing the role clears the board');
            $again = vanilla_submit($match, 'host', 'claimVoidLord', []);
            expect_same([[], 0], [$again['units'], $again['setup']['spent']], 'a new claim starts empty');
        },

        'lobby setup orders are refused outside a co-op war room' => static function (): void {
            $duel = vanilla_service_match();
            expect_game_error('unsupportedCommand', static fn() => vanilla_submit($duel, 'host', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '0,0']));
            $match = coop_service_match(1);
            expect_game_error('unsupportedCommand', static fn() => vanilla_submit($match, 'host', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '0,0']));
        },

        'the lobby board is deleted with the room' => static function (): void {
            $match = coop_service_match(0);
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_lobby_place($match, 'host', 'Test Wolf');
            expect_same(1, coop_lobby_state_count($match), 'the board is stored');
            $match['games']->leaveGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            expect_same(0, coop_lobby_state_count($match), 'the empty room takes the board with it');
        },

        'the war room lets the void lord go over the current budget, up to a full table' => static function (): void {
            $match = coop_service_match(1);
            $claimed = vanilla_submit($match, 'host', 'claimVoidLord', []);
            expect_same([12, 36], [$claimed['setup']['budget'], $claimed['setup']['maxBudget']], 'one hero now, four at most: 4 + 8 × 4');
            foreach (range(1, 9) as $ignored) {
                $placed = coop_lobby_place($match, 'host', 'Test Wyrm');
            }
            expect_same([12, 36], [$placed['setup']['budget'], $placed['setup']['spent']], 'nine wyrms fill the largest budget');
            expect_true(!in_array('placeMonster', array_column($placed['legalActions'], 'kind'), true), 'nothing fits past a full table');
            $free = coop_lobby_free_wolf_hex($claimed, $placed);
            expect_game_error('budgetExceeded', static fn() => vanilla_submit($match, 'host', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => $free]));
        },

        'after the start the budget is strict again' => static function (): void {
            $match = coop_service_match(1);
            $claimed = vanilla_submit($match, 'host', 'claimVoidLord', []);
            foreach (range(1, 4) as $ignored) {
                coop_lobby_place($match, 'host', 'Test Wyrm');
            }
            coop_ready_all($match);
            $started = $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            expect_same([12, 16, null], [$started['setup']['budget'], $started['setup']['spent'], $started['setup']['maxBudget']], 'over the final budget');
            $kinds = array_column($started['legalActions'], 'kind');
            expect_true(!in_array('placeMonster', $kinds, true) && !in_array('confirmSetup', $kinds, true), 'no placement or confirm while over');
            expect_true(in_array('removeMonster', $kinds, true), 'removal is how to get back under');
            $free = coop_lobby_free_wolf_hex($claimed, $started);
            expect_game_error('budgetExceeded', static fn() => vanilla_submit($match, 'host', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => $free]));
            expect_game_error('budgetExceeded', static fn() => vanilla_submit($match, 'host', 'confirmSetup', []));
        },

        'start carries the lobby horde into prep and opens the draft' => static function (): void {
            $match = coop_service_match(1);
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_lobby_place($match, 'host', 'Test Wolf');
            coop_ready_all($match);
            $started = $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            expect_same('draft', $started['lifecycle'], 'prep shows as the draft lifecycle');
            expect_same(['v-m1'], array_column($started['units'], 'unitId'), 'the lobby wolf is kept');
            expect_same([12, 1], [$started['setup']['budget'], $started['setup']['spent']], 'budget and spend carry over');
            expect_true(in_array('confirmSetup', array_column($started['legalActions'], 'kind'), true), 'the void lord may now confirm');
            $hero = $match['games']->getGame($match['gameId'], $match['hero1']['identity']);
            expect_same(['pickHero', 'pickArmy', 'setCommanderName'], array_column($hero['legalActions'], 'kind'), 'the hero drafts at once');
        },

        'draft first, then the confirm starts the battle' => static function (): void {
            $match = coop_service_match(1);
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_ready_all($match);
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            coop_lobby_place($match, 'host', 'Test Wolf');
            vanilla_submit($match, 'hero1', 'pickHero', ['hero' => 'Test Captain']);
            vanilla_submit($match, 'hero1', 'pickHero', ['hero' => 'Test Marshal']);
            $waiting = vanilla_submit($match, 'hero1', 'pickArmy', ['army' => 'Red Company']);
            expect_same('draft', $waiting['lifecycle'], 'the heroes wait for the void lord');
            $active = vanilla_submit($match, 'host', 'confirmSetup', []);
            expect_same('active', $active['lifecycle'], 'the confirm starts the battle');
            expect_same($match['hero1']['seat'], $active['turn']['activeSeatId'], 'the hero acts first');
        },

        'confirm first, then the draft finishes the battle start' => static function (): void {
            $match = coop_service_match(1);
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_lobby_place($match, 'host', 'Test Wolf');
            coop_ready_all($match);
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            $confirmed = vanilla_submit($match, 'host', 'confirmSetup', []);
            expect_same(['draft', true], [$confirmed['lifecycle'], $confirmed['setup']['confirmed']], 'the void lord waits');
            vanilla_submit($match, 'hero1', 'pickHero', ['hero' => 'Test Captain']);
            vanilla_submit($match, 'hero1', 'pickHero', ['hero' => 'Test Marshal']);
            $active = vanilla_submit($match, 'hero1', 'pickArmy', ['army' => 'Red Company']);
            expect_same('active', $active['lifecycle'], 'the last pick starts the battle');
        },
    ];
}
