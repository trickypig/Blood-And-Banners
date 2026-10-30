<?php
declare(strict_types=1);

use BloodAndBanners\Ai\Personalities;
use BloodAndBanners\Ai\Personality;
use BloodAndBanners\Ai\SeededRandom;
use BloodAndBanners\Ai\TurnPlanner;
use BloodAndBanners\Rules\Vanilla\Board;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Terrain;
use BloodAndBanners\Rules\Vanilla\Turn;

const AI_PLANNER_NOW = 1_000_000;

function ai_planner(string $personality, ?MatchEngine $engine = null, int $budgetMs = TurnPlanner::STEP_BUDGET_MS, ?Closure $clock = null): TurnPlanner
{
    $start = $clock !== null ? $clock() : (int)floor(microtime(true) * 1000);
    return new TurnPlanner($engine ?? new MatchEngine(test_catalog()), Personalities::get($personality), AI_PLANNER_NOW, $start + $budgetMs, $clock);
}

/** The Void Lord's turn with $active already activated (or nothing active when null). */
function ai_planner_void_turn(array $units, ?string $active = null): array
{
    $state = coop_battle($units, 'void-lord', 2);
    if ($active !== null) {
        $state['turn']['activeUnitId'] = $active;
        $state['turn']['activated'] = [$active];
    }
    return $state;
}

/** Applies planner steps on the Void Lord's turn until it passes; returns the final state. */
function ai_planner_play_turn(array $state, string $personality, MatchEngine $engine, int $seed = 1, int $limit = 12): array
{
    for ($i = 0; $i < $limit && $state['turn']['activeSeatId'] === 'void-lord' && $state['phase'] === 'active'; $i++) {
        $command = ai_planner($personality, $engine)->next($state, 'void-lord', $seed + $i);
        expect_true($command !== null, "{$personality} owes a command on its turn");
        $state = $engine->apply($state, 'void-lord', $command['kind'], $command['payload'], new SeededRandom($seed + $i), AI_PLANNER_NOW)['state'];
    }
    expect_true($state['turn']['activeSeatId'] !== 'void-lord', "{$personality} finishes its turn");
    return $state;
}

/** 16 × 3 strip: void spawn hexes at q >= 13, the Void Portal at 15,1. */
function ai_planner_strip(): array
{
    $elevations = [];
    for ($q = 0; $q < 16; $q++) {
        for ($r = 0; $r < 3; $r++) {
            $elevations["{$q},{$r}"] = 0;
        }
    }
    $board = vanilla_board($elevations);
    foreach ($board as $key => $hex) {
        $board[$key]['spawnZone'] = (int)explode(',', (string)$key)[0] >= 13 ? 'void' : null;
        $board[$key]['voidPortal'] = $key === '15,1';
    }
    return $board;
}

/** Drafts by taking the first legal pick for whichever seat is picking, until the battle begins. */
function ai_draft_to_battle(array $state): array
{
    $engine = new MatchEngine(ai_catalog());
    $heroNames = array_column(ai_catalog()->heroes(), 'name');
    $armyNames = array_column(ai_catalog()->armies(), 'name');
    while ($state['phase'] !== 'active') {
        $seat = (string)$state['draft']['pickingSeatId'];
        $kinds = array_column($engine->legalActions($state, $seat), 'kind');
        $kind = in_array('pickHero', $kinds, true) ? 'pickHero' : 'pickArmy';
        if ($kind === 'pickHero') {
            $taken = array_merge(...array_values($state['draft']['heroesBySeat']));
            $payload = ['hero' => array_values(array_diff($heroNames, $taken))[0]];
        } else {
            $payload = ['army' => array_values(array_diff($armyNames, array_filter($state['draft']['armyBySeat'])))[0]];
        }
        $state = $engine->apply($state, $seat, $kind, $payload, new SeededRandom(1))['state'];
    }
    return $state;
}

/** Void Reaches with the personality's horde, the draft finished and the board shuffled into a seeded mid-battle. */
function ai_planner_reaches(string $personality, int $seed, int $heroes = 2): array
{
    static $battles = [];
    $key = "{$personality}:{$heroes}";
    if (!isset($battles[$key])) {
        $state = ai_prep_state('void-reaches', $heroes);
        $state = ai_apply_plan($state, ai_plan($state, $personality));
        $state = ai_draft_to_battle($state);
        expect_same('active', $state['phase'], 'the battle begins');
        $battles[$key] = $state;
    }
    $state = $battles[$key];
    $random = new SeededRandom($seed);
    $monsters = [];
    $occupied = [];
    foreach ($state['units'] as $id => $unit) {
        if ($unit['hex'] !== null) {
            $occupied[$unit['hex']] = true;
            if ($unit['side'] === 'void' && empty($unit['fixed'])) {
                $monsters[] = $unit['hex'];
            }
        }
    }
    foreach ($state['units'] as $id => $unit) {
        if ($unit['side'] !== 'heroes' || $unit['hex'] === null) {
            continue;
        }
        // Scatter each hero-side unit near a random monster, onto plain ground.
        $anchor = $monsters[$random->roll(count($monsters)) - 1];
        $reach = $random->roll(6);
        $spots = array_values(array_filter(array_keys($state['board']), static fn (string $hex): bool =>
            !isset($occupied[$hex]) && !Board::impassable($state['board'][$hex]) && Terrain::type($state['board'][$hex]) === null
            && empty($state['board'][$hex]['object']) && Hex::distance($hex, $anchor) <= $reach));
        if ($spots !== []) {
            unset($occupied[$unit['hex']]);
            $hex = $spots[$random->roll(count($spots)) - 1];
            $state['units'][$id]['hex'] = $hex;
            $occupied[$hex] = true;
        }
        $state['units'][$id]['wounds'] = $random->roll((int)$unit['hp']) - 1;
    }
    $state['turn'] = Turn::fresh($state, 'void-lord', $random->roll(4));
    return $state;
}

/**
 * Four powerless monsters hug a hero on an open 16 × 5 board and one more stands far off: only the far one
 * can gain by moving. Returns the Void Lord's turn and an advance-only personality searching $candidates units.
 * @return array{0: array, 1: Personality}
 */
function ai_planner_front_line(int $candidates): array
{
    $elevations = [];
    for ($q = 0; $q < 16; $q++) {
        for ($r = 0; $r < 5; $r++) {
            $elevations["{$q},{$r}"] = 0;
        }
    }
    $units = [coop_hero('h1', 'hero-a', '2,2', ['hp' => 9])];
    foreach (['1,2', '3,2', '2,1', '2,3'] as $i => $hex) {
        $units[] = vanilla_unit("v-near{$i}", 'void-lord', $hex, ['side' => 'void', 'tier' => 'common', 'power' => 0, 'range' => 0, 'move' => 1]);
    }
    $units[] = vanilla_unit('v-far', 'void-lord', '15,2', ['side' => 'void', 'tier' => 'common', 'move' => 3]);
    $state = coop_battle($units, 'void-lord', 2);
    $state['board'] = vanilla_board($elevations);
    $narrow = new Personality('narrow', 'Narrow', 'test', 'Only advances.', ['advance' => 1.0], [],
        ['candidates' => $candidates, 'samples' => 1, 'blunder' => 0, 'pairs' => false], ['mode' => 'attack']);
    return [$state, $narrow];
}

function ai_planner_tests(): array
{
    return [
        'it attacks a hero standing in reach' => static function (): void {
            $state = ai_planner_void_turn([
                coop_hero('h1', 'hero-a', '2,0', ['hp' => 1, 'defense' => 1]),
                vanilla_unit('v-m1', 'void-lord', '3,0', ['side' => 'void', 'tier' => 'common', 'power' => 3]),
            ], 'v-m1');
            foreach (array_keys(Personalities::all()) as $id) {
                foreach ([1, 2, 3, 4, 5] as $seed) {
                    $command = ai_planner($id)->next($state, 'void-lord', $seed);
                    expect_same(['kind' => 'attackUnit', 'payload' => ['unitId' => 'v-m1', 'targetUnitId' => 'h1']], $command, "{$id} seed {$seed} attacks");
                }
            }
        },
        'it activates the monster beside a hero rather than ending the turn' => static function (): void {
            $state = ai_planner_void_turn([
                coop_hero('h1', 'hero-a', '2,0', ['hp' => 4]),
                vanilla_unit('v-m1', 'void-lord', '3,0', ['side' => 'void', 'tier' => 'common', 'power' => 3]),
            ]);
            foreach (array_keys(Personalities::all()) as $id) {
                $command = ai_planner($id)->next($state, 'void-lord', 1);
                expect_true($command['kind'] !== 'endTurn', "{$id} uses its monster");
                expect_same('v-m1', $command['payload']['unitId'] ?? null, "{$id} picks the monster in reach");
            }
        },
        'it never submits an illegal command' => static function (): void {
            $engine = new MatchEngine(ai_catalog());
            $ids = array_keys(Personalities::all());
            for ($seed = 1; $seed <= 20; $seed++) {
                $personality = $ids[$seed % 4];
                $state = ai_planner_reaches($personality, $seed);
                for ($step = 0; $step < 3 && $state['phase'] === 'active'; $step++) {
                    $command = ai_planner($personality, $engine, 300)->next($state, 'void-lord', $seed * 10 + $step);
                    if ($command === null) {
                        break;
                    }
                    $state = $engine->apply($state, 'void-lord', $command['kind'], $command['payload'], new SeededRandom($seed), AI_PLANNER_NOW)['state'];
                }
            }
            expect_true(true, 'every command applied');
        },
        'after a unit is done it goes straight to the next unit, not a silent endActivation' => static function (): void {
            $engine = new MatchEngine(test_catalog());
            // v-m1 has moved and has no one in reach: it is done. v-m2 stands beside a hero.
            $state = ai_planner_void_turn([
                coop_hero('h1', 'hero-a', '2,0', ['hp' => 6, 'defense' => 1]),
                vanilla_unit('v-m1', 'void-lord', '7,0', ['side' => 'void', 'tier' => 'common', 'move' => 1]),
                vanilla_unit('v-m2', 'void-lord', '3,0', ['side' => 'void', 'tier' => 'common', 'power' => 3]),
            ], 'v-m1');
            $state['turn']['moved'] = true;
            $state['turn']['moveSpent'] = 1;
            foreach (array_keys(Personalities::all()) as $id) {
                $command = ai_planner($id, $engine)->next($state, 'void-lord', 1);
                expect_true($command['kind'] !== 'endActivation', "{$id} does not spend a step on endActivation ({$command['kind']})");
                expect_same('v-m2', $command['payload']['unitId'] ?? null, "{$id} acts with the next unit");
                // Legal as it stands, with v-m1 still active.
                $engine->apply($state, 'void-lord', $command['kind'], $command['payload'], new SeededRandom(1), AI_PLANNER_NOW);
            }
            // With nobody else able to act, it ends the turn instead.
            $alone = $state;
            $alone['turn']['activated'] = ['v-m1', 'v-m2'];
            $alone['turn']['allowance'] = 2;
            foreach (array_keys(Personalities::all()) as $id) {
                $command = ai_planner($id, $engine)->next($alone, 'void-lord', 1);
                expect_same('endTurn', $command['kind'], "{$id} ends the turn");
            }
        },
        'a kraken beside open water summons a tentacle' => static function (): void {
            $engine = new MatchEngine(ai_catalog());
            // The catalog's own Kraken (power 0, Water Bound) at the near edge of a pool, a Merman beside it (already
            // activated), the heroes far off. Moving gains nothing; a tentacle is a free unit.
            $board = ai_planner_strip();
            foreach ($board as $key => $hex) {
                if ((int)explode(',', (string)$key)[0] >= 12) {
                    $board[$key]['terrain'] = 'shallow-water';
                }
            }
            $state = ai_planner_void_turn([
                coop_hero('h1', 'hero-a', '2,1', ['hp' => 6]),
                monster_unit('v-kraken', 'Kraken', '12,1'),
                monster_unit('v-merman', 'Merman', '11,1'),
            ]);
            $state['board'] = $board;
            $state['turn']['activated'] = ['v-merman'];
            $tentacles = static fn (array $s): int => count(array_filter($s['units'], static fn (array $u): bool
                => $u['hex'] !== null && str_starts_with((string)$u['name'], 'Kraken Tentacle')));
            foreach (array_keys(Personalities::all()) as $id) {
                $command = ai_planner($id, $engine)->next($state, 'void-lord', 1);
                expect_same('specialAttack', $command['kind'], "{$id} uses the kraken");
                expect_same('Tentacle Regeneration', $command['payload']['ability'] ?? null, "{$id} summons a tentacle");
                $after = $engine->apply($state, 'void-lord', $command['kind'], $command['payload'], new SeededRandom(1), AI_PLANNER_NOW)['state'];
                expect_same(1, $tentacles($after), "{$id} has a tentacle");
            }
        },
        'it ends the turn when nothing is left' => static function (): void {
            $state = ai_planner_void_turn([
                coop_hero('h1', 'hero-a', '0,0'),
                vanilla_unit('v-m1', 'void-lord', '7,0', ['side' => 'void', 'tier' => 'common']),
                vanilla_unit('v-m2', 'void-lord', '8,0', ['side' => 'void', 'tier' => 'common']),
            ]);
            $state['turn']['activated'] = ['v-m1', 'v-m2'];
            $state['turn']['allowance'] = 2;
            foreach (array_keys(Personalities::all()) as $id) {
                expect_same(['kind' => 'endTurn', 'payload' => []], ai_planner($id)->next($state, 'void-lord', 1), "{$id} ends the turn");
            }
        },
        'it owes nothing on a hero turn' => static function (): void {
            $state = coop_battle([
                coop_hero('h1', 'hero-a', '0,0'),
                vanilla_unit('v-m1', 'void-lord', '7,0', ['side' => 'void', 'tier' => 'common']),
            ], 'hero-a');
            expect_same(null, ai_planner('warden')->next($state, 'void-lord', 1), 'nothing owed');
            $over = ai_planner_void_turn([coop_hero('h1', 'hero-a', '0,0'), vanilla_unit('v-m1', 'void-lord', '7,0', ['side' => 'void'])]);
            $over['phase'] = 'complete';
            $over['result'] = ['reason' => 'heroesDefeated', 'winningSeatId' => 'void-lord', 'winningSide' => 'void'];
            expect_same(null, ai_planner('warden')->next($over, 'void-lord', 1), 'game over');
        },
        'it answers its own pending choice even on a hero turn' => static function (): void {
            $state = coop_battle([
                coop_hero('h1', 'hero-a', '0,0'),
                vanilla_unit('v-m1', 'void-lord', '6,0', ['side' => 'void', 'tier' => 'common']),
            ], 'hero-a');
            $state['board']['6,0']['object'] = 'power-potion';
            $state['pending'] = ['choice' => [
                'choiceId' => 'choice-7', 'seatId' => 'void-lord', 'kind' => 'itemPickup', 'unitId' => 'v-m1',
                'item' => 'power-potion', 'options' => ['drink', 'keep', 'leave'], 'targetHexes' => ['6,0'], 'openedAt' => AI_PLANNER_NOW,
            ], 'context' => []];
            foreach (array_keys(Personalities::all()) as $id) {
                $command = ai_planner($id)->next($state, 'void-lord', 1);
                expect_same('resolveChoice', $command['kind'] ?? null, "{$id} resolves");
                expect_same('choice-7', $command['payload']['choiceId'], "{$id} answers that choice");
                expect_true(in_array($command['payload']['option'], ['drink', 'keep', 'leave'], true), "{$id} picks an offered option");
            }
            expect_same(null, ai_planner('warden')->next($state, 'hero-a', 1), 'not the hero seat\'s choice');
        },
        'the warden holds while heroes are far, the ravager charges' => static function (): void {
            $engine = new MatchEngine(test_catalog());
            $state = coop_battle([
                coop_hero('h1', 'hero-a', '4,1', ['hp' => 6]),
                coop_hero('h2', 'hero-b', '4,0', ['hp' => 6]),
                vanilla_unit('v-m1', 'void-lord', '14,1', ['side' => 'void', 'tier' => 'common', 'move' => 4]),
                vanilla_unit('v-portal', 'void-lord', '15,1', ['side' => 'void', 'fixed' => true, 'move' => 0, 'power' => 0, 'hp' => 8, 'tier' => 'boss']),
            ], 'void-lord', 2);
            $state['board'] = ai_planner_strip();
            foreach ([1, 2, 3] as $seed) {
                $warden = ai_planner_play_turn($state, 'warden', $engine, $seed);
                expect_true(Hex::distance('14,1', $warden['units']['v-m1']['hex']) <= 2, "warden holds (seed {$seed}, at {$warden['units']['v-m1']['hex']})");
                $ravager = ai_planner_play_turn($state, 'ravager', $engine, $seed);
                expect_true(Hex::distance('4,1', $ravager['units']['v-m1']['hex']) < 10, "ravager charges (seed {$seed}, at {$ravager['units']['v-m1']['hex']})");
            }
        },
        'the warden leaves its post once a hero comes close' => static function (): void {
            $state = ai_planner_void_turn([
                coop_hero('h1', 'hero-a', '2,0', ['hp' => 1]),
                vanilla_unit('v-m1', 'void-lord', '4,0', ['side' => 'void', 'tier' => 'common', 'move' => 2]),
            ]);
            $command = ai_planner('warden')->next($state, 'void-lord', 1);
            expect_same('moveUnit', $command['kind'], 'warden steps in');
            expect_same('3,0', $command['payload']['to'], 'next to the hero');
        },
        'a far monster still advances when the front line has nothing to gain' => static function (): void {
            // With room to search only four units, the planner must still look past the nearest four.
            [$state, $narrow] = ai_planner_front_line(4);
            $start = (int)floor(microtime(true) * 1000);
            $command = (new TurnPlanner(new MatchEngine(test_catalog()), $narrow, AI_PLANNER_NOW, $start + 5000))->next($state, 'void-lord', 1);
            expect_same('moveUnit', $command['kind'] ?? null, 'it moves rather than ending the turn');
            expect_same('v-far', $command['payload']['unitId'], 'the far monster moves');
        },
        'it does not pass the turn just because the clock ran out' => static function (): void {
            [$state, $narrow] = ai_planner_front_line(8);
            // The clock expires during the first unit's scan, so the search itself finds nothing.
            $tick = 0;
            $clock = static function () use (&$tick): int {
                return $tick++;
            };
            $command = (new TurnPlanner(new MatchEngine(test_catalog()), $narrow, AI_PLANNER_NOW, 3, $clock))->next($state, 'void-lord', 1);
            expect_same('moveUnit', $command['kind'] ?? null, 'it still moves');
            expect_same('v-far', $command['payload']['unitId'], 'the far monster moves');
            $nothing = $state;
            $nothing['units']['v-far'] = ['hex' => '3,1', 'power' => 0, 'range' => 0] + $nothing['units']['v-far'];
            $tick = 0;
            $command = (new TurnPlanner(new MatchEngine(test_catalog()), $narrow, AI_PLANNER_NOW, 3, $clock))->next($nothing, 'void-lord', 1);
            expect_same('endTurn', $command['kind'] ?? null, 'with nothing to gain it still passes');
        },
        'it respects the deadline' => static function (): void {
            $tick = -1000;
            $clock = static function () use (&$tick): int {
                return $tick += 1000;
            };
            $engine = new MatchEngine(ai_catalog());
            $state = ai_planner_reaches('hollowKing', 3);
            $command = ai_planner('hollowKing', $engine, 1500, $clock)->next($state, 'void-lord', 1);
            expect_true($command !== null, 'still answers');
            $engine->apply($state, 'void-lord', $command['kind'], $command['payload'], new SeededRandom(1), AI_PLANNER_NOW);
            $active = ai_planner_void_turn([
                coop_hero('h1', 'hero-a', '2,0', ['hp' => 4]),
                vanilla_unit('v-m1', 'void-lord', '4,0', ['side' => 'void', 'tier' => 'common']),
            ], 'v-m1');
            $tick = -1000;
            $command = ai_planner('hollowKing', null, 1500, $clock)->next($active, 'void-lord', 1);
            (new MatchEngine(test_catalog()))->apply($active, 'void-lord', $command['kind'], $command['payload'], new SeededRandom(1), AI_PLANNER_NOW);
        },
    ];
}
