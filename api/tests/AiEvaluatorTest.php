<?php
declare(strict_types=1);

use BloodAndBanners\Ai\Evaluator;
use BloodAndBanners\Ai\Personalities;
use BloodAndBanners\Ai\SeededRandom;
use BloodAndBanners\Ai\Simulator;
use BloodAndBanners\Rules\Vanilla\MatchEngine;

function ai_eval_units(int $wounds, string $monsterHex = '5,0'): array
{
    return [
        coop_hero('h1', 'hero-a', '2,0', ['hp' => 5, 'wounds' => $wounds]),
        vanilla_unit('v-m1', 'void-lord', $monsterHex, ['side' => 'void', 'tier' => 'common']),
    ];
}

function ai_evaluator_tests(): array
{
    return [
        'seeded random repeats per seed' => static function (): void {
            $seq = static function (int $seed): array {
                $r = new SeededRandom($seed);
                return array_map(static fn () => $r->roll(6), range(1, 20));
            };
            expect_same($seq(7), $seq(7), 'same seed');
            expect_true($seq(7) !== $seq(8), 'different seed differs');
            expect_true(min($seq(7)) >= 1 && max($seq(7)) <= 6, 'in range');
        },
        'an illegal candidate has no outcomes' => static function (): void {
            $state = coop_battle(ai_eval_units(0, '3,0'), 'void-lord', 1);
            $sim = new Simulator(new MatchEngine(test_catalog()), 1000);
            expect_same([], $sim->outcomes($state, 'void-lord', 'attackUnit', ['unitId' => 'v-m1', 'targetUnitId' => 'nobody'], 2, 1), 'illegal');
        },
        'a candidate the engine chokes on is dropped and logged once per step' => static function (): void {
            $state = coop_battle(ai_eval_units(0, '3,0'), 'void-lord', 1);
            $sim = new Simulator(new MatchEngine(test_catalog()), 1000);
            $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ai-simulator-test-' . bin2hex(random_bytes(4)) . '.log';
            $previous = ini_set('error_log', $log);
            try {
                // A malformed payload the rules never validated: the engine throws a TypeError, not a rules error.
                $payload = ['unitId' => 'v-m1', 'ability' => 'Anything', 'targetUnitIds' => 'h1'];
                expect_same([], $sim->outcomes($state, 'void-lord', 'specialAttack', $payload, 2, 1), 'dropped');
                expect_same([], $sim->outcomes($state, 'void-lord', 'specialAttack', $payload, 2, 2), 'dropped again');
            } finally {
                ini_set('error_log', (string)$previous);
            }
            $lines = is_file($log) ? array_values(array_filter(explode("\n", (string)file_get_contents($log)))) : [];
            @unlink($log);
            expect_same(1, count($lines), 'logged once for the step');
            expect_true(str_contains($lines[0] ?? '', '[ai]') && str_contains($lines[0] ?? '', 'TypeError'), 'with the [ai] prefix and the error');
        },
        'a legal candidate yields one outcome per sample and leaves the input alone' => static function (): void {
            $state = coop_battle([
                coop_hero('h1', 'hero-a', '2,0', ['hp' => 9]),
                vanilla_unit('v-m1', 'void-lord', '3,0', ['side' => 'void', 'tier' => 'common', 'power' => 3]),
            ], 'void-lord', 1);
            $before = $state;
            $sim = new Simulator(new MatchEngine(test_catalog()), 1000);
            $payload = ['unitId' => 'v-m1', 'targetUnitId' => 'h1'];
            $a = $sim->outcomes($state, 'void-lord', 'attackUnit', $payload, 8, 5);
            expect_same(8, count($a), 'one per sample');
            expect_same($a, $sim->outcomes($state, 'void-lord', 'attackUnit', $payload, 8, 5), 'same seed repeats');
            expect_same($before, $state, 'input untouched');
            expect_true(count(array_unique(array_map('serialize', $a))) > 1, 'different seeds diverge');
        },
        'seeds near 32 bits stay valid and deterministic' => static function (): void {
            foreach ([crc32('some-game-id'), 4294967295, 0] as $seed) {
                $seq = static function () use ($seed): array {
                    $r = new SeededRandom($seed);
                    return array_map(static fn () => $r->roll(6), range(1, 20));
                };
                expect_same($seq(), $seq(), 'repeats');
                expect_true(min($seq()) >= 1 && max($seq()) <= 6, 'range');
            }
        },
        'the hero side scores its losses without tiers' => static function (): void {
            $army = vanilla_unit('s1-u1', 'hero-a', '1,0', ['hp' => 2]);
            unset($army['tier']);
            $state = coop_battle([coop_hero('h1', 'hero-a', '2,0'), $army, vanilla_unit('v-m1', 'void-lord', '5,0', ['side' => 'void', 'tier' => 'common'])], 'hero-a');
            $state['units']['s1-u1']['hex'] = null;
            $warnings = [];
            set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
                $warnings[] = $message;
                return true;
            });
            try {
                $score = (new Evaluator(Personalities::get('ravager')))->score($state, 'heroes', ['loss' => 1.0]);
            } finally {
                restore_error_handler();
            }
            expect_same([], $warnings, 'no warnings');
            expect_true(abs($score + 0.2) < 1e-9, "a lost army unit counts as a common beside a 4-point hero, as a penalty ({$score})");
        },
        'each feature scores on its own' => static function (): void {
            $only = static fn (string $k): array => [$k => 1.0];
            $score = static fn (array $units, string $k, string $side = 'void'): float =>
                (new Evaluator(Personalities::get('ravager')))->score(coop_battle($units), $side, $only($k));
            $hero = static fn (string $id, ?string $hex, array $s = []) => coop_hero($id, 'hero-a', $hex, $s + ['hp' => 4]);
            $mon = static fn (string $id, ?string $hex, array $s = []) => vanilla_unit($id, 'void-lord', $hex, $s + ['side' => 'void', 'tier' => 'common']);

            expect_true($score([$hero('h1', null), $hero('h2', '0,0'), $mon('m1', '5,0')], 'kill', 'void') === 0.5, 'kill share');
            $nothingLost = $score([$mon('m1', '5,0', ['tier' => 'elite']), $mon('m2', '6,0'), $hero('h1', '0,0'), $hero('h2', '1,0')], 'loss', 'heroes');
            expect_same(0.0, $nothingLost, 'nothing lost');
            $heroLost = $score([$hero('h1', null), $hero('h2', '1,0'), $mon('m1', '5,0')], 'loss', 'heroes');
            expect_true(abs($heroLost + 0.5) < 1e-9, 'dead hero is 4 of 8, scored as a penalty');
            $eliteLost = $score([$mon('m1', null, ['tier' => 'elite']), $mon('m2', '6,0'), $mon('m3', '7,0'), $hero('h1', '0,0')], 'loss');
            expect_true(abs($eliteLost + 0.5) < 1e-9, 'dead elite is 2 of 4, scored as a penalty');
            expect_true($score([$mon('m1', '5,0'), $mon('m2', '6,0'), $hero('h1', '0,0')], 'cohesion') === 1.0, 'cohesion');
            expect_true($score([$mon('m1', '4,0'), $mon('m2', '8,0'), $hero('h1', '0,0')], 'cohesion') === 0.0, 'no cohesion');
            expect_true($score([$mon('m1', '8,0', ['move' => 2, 'range' => 1]), $hero('h1', '0,0')], 'threat', 'heroes') === 0.0, 'out of reach');
            expect_true($score([$mon('m1', '2,0', ['move' => 2, 'range' => 1]), $hero('h1', '0,0')], 'threat', 'heroes') === -1.0, 'threatened');
            expect_true(abs($score([$hero('h1', '0,0', ['wounds' => 1]), $hero('h2', '1,0', ['wounds' => 3]), $mon('m1', '5,0')], 'focus') - 0.75) < 1e-9, 'focus');
            $near = $score([$mon('m1', '2,0'), $hero('h1', '0,0')], 'advance');
            $far = $score([$mon('m1', '8,0'), $hero('h1', '0,0')], 'advance');
            expect_true($near > $far, 'closer is better');
            $portal = static fn (string $hex): array => vanilla_unit('v-portal', 'void-lord', $hex, ['side' => 'void', 'fixed' => true]);
            $guarded = coop_battle([$mon('m1', '3,0'), $hero('h1', '8,0'), $portal('2,0')]);
            $exposed = coop_battle([$mon('m1', '8,0'), $hero('h1', '3,0'), $portal('2,0')]);
            $e = new Evaluator(Personalities::get('ravager'));
            expect_true($e->score($guarded, 'void', $only('portalGuard')) > $e->score($exposed, 'void', $only('portalGuard')), 'portal guard');
        },
        'material counts every unit fielded against the budget, so a summon gains and a death is left to loss' => static function (): void {
            $only = ['material' => 1.0];
            $e = new Evaluator(Personalities::get('ravager'));
            $mon = static fn (string $id, ?string $hex, array $s = []) => vanilla_unit($id, 'void-lord', $hex, $s + ['side' => 'void', 'tier' => 'common']);
            $hero = coop_hero('h1', 'hero-a', '0,0', ['hp' => 4]);
            // coop_battle budgets 20 points.
            $two = coop_battle([$mon('m1', '5,0', ['tier' => 'boss']), $mon('m2', '6,0'), $hero]);
            expect_true(abs($e->score($two, 'void', $only) - 5 / 20) < 1e-9, 'a boss and a common are 5 of 20');
            $summoned = $two;
            $summoned['units']['m3'] = $mon('m3', '7,0');
            expect_true(abs($e->score($summoned, 'void', $only) - 6 / 20) < 1e-9, 'a summoned common adds 1');
            $dead = $two;
            $dead['units']['m2']['hex'] = null;
            expect_same($e->score($two, 'void', $only), $e->score($dead, 'void', $only), 'a death does not move material (loss scores it)');
            expect_true(Personalities::get('warden')->weights['material'] > 0, 'personalities value material');
            // Until it has had a turn to act, a summoned unit does not count where the horde stands.
            $fresh = $two;
            $fresh['units']['m3'] = $mon('m3', '8,0', ['flags' => ['summonedOnTurn' => $two['turn']['number']]]);
            $advance = ['advance' => 1.0];
            expect_same($e->score($two, 'void', $advance), $e->score($fresh, 'void', $advance), 'a fresh summon behind the line is not a retreat');
            $later = $fresh;
            $later['turn']['number'] += 2;
            expect_true($e->score($later, 'void', $advance) < $e->score($two, 'void', $advance), 'once it could act, it counts');
        },
        'empty sides and off-board units give finite scores' => static function (): void {
            $p = Personalities::get('warden');
            $e = new Evaluator($p);
            $mon = vanilla_unit('m1', 'void-lord', '3,0', ['side' => 'void', 'tier' => 'common']);
            $noEnemies = coop_battle([$mon]);
            $offBoard = coop_battle([coop_hero('h1', 'hero-a', null), $mon]);
            foreach ([$noEnemies, $offBoard] as $state) {
                foreach (['void', 'heroes'] as $side) {
                    expect_true(is_finite($e->score($state, $side, $p->weights)), "finite for {$side}");
                }
            }
        },
        'a win by seat resolves through sides and an unknown seat is no result' => static function (): void {
            $e = new Evaluator(Personalities::get('ravager'));
            $w = ['damage' => 1.0];
            $state = coop_battle(ai_eval_units(0));
            $state['result'] = ['winningSeatId' => 'hero-b'];
            expect_true($e->score($state, 'heroes', $w) > 1e5 && $e->score($state, 'void', $w) < -1e5, 'seat mapped');
            $state['result'] = ['winningSeatId' => 'ghost'];
            expect_true(abs($e->score($state, 'void', $w)) < 1e5, 'unknown seat ignored');
        },
        'scoring a Co-op sized state is fast' => static function (): void {
            $def = json_decode((string)file_get_contents(__DIR__ . '/../scenarios/void-reaches.json'), true);
            $board = \BloodAndBanners\Rules\Vanilla\Board::fromScenario($def);
            $keys = array_keys($board);
            $units = [];
            for ($i = 0; $i < 10; $i++) {
                $units[] = vanilla_unit("m{$i}", 'void-lord', $keys[$i * 7], ['side' => 'void', 'tier' => 'common']);
            }
            for ($i = 0; $i < 5; $i++) {
                $units[] = coop_hero("h{$i}", 'hero-a', $keys[count($keys) - 1 - $i * 5], ['hp' => 5]);
            }
            $state = coop_battle($units);
            $state['board'] = $board;
            $p = Personalities::get('huntress');
            $e = new Evaluator($p);
            $start = microtime(true);
            for ($i = 0; $i < 200; $i++) {
                $e->score($state, 'void', $p->weights);
            }
            $ms = (microtime(true) - $start) * 1000 / 200;
            fwrite(STDERR, sprintf("  evaluator: %.2f ms per score\n", $ms));
            expect_true($ms < 20, 'under 20 ms per score');
        },
        'wounding a hero scores higher for the void' => static function (): void {
            $p = Personalities::get('ravager');
            $e = new Evaluator($p);
            $fresh = coop_battle(ai_eval_units(0));
            $hurt = coop_battle(ai_eval_units(2));
            expect_true($e->score($hurt, 'void', $p->weights) > $e->score($fresh, 'void', $p->weights), 'void likes wounds');
            expect_true($e->score($hurt, 'heroes', $p->weights) < $e->score($fresh, 'heroes', $p->weights), 'heroes dislike wounds');
        },
        'a void win dominates' => static function (): void {
            $state = coop_battle(ai_eval_units(0));
            $state['result'] = ['winningSide' => 'void'];
            $p = Personalities::get('ravager');
            $e = new Evaluator($p);
            expect_true($e->score($state, 'void', $p->weights) > 1e5, 'void win');
            expect_true($e->score($state, 'heroes', $p->weights) < -1e5, 'hero loss');
        },
        'the huntress values hiding more than the ravager' => static function (): void {
            $gap = static function (string $id): float {
                $p = Personalities::get($id);
                $e = new Evaluator($p);
                $units = static fn () => [
                    coop_hero('h1', 'hero-a', '0,0', ['hp' => 5]),
                    vanilla_unit('v-m1', 'void-lord', '2,0', ['side' => 'void', 'tier' => 'common']),
                ];
                $open = coop_battle($units());
                $hidden = coop_battle($units());
                $hidden['board'] = vanilla_board(vanilla_line(9), ['1,0']);
                return $e->score($hidden, 'void', $p->weights) - $e->score($open, 'void', $p->weights);
            };
            expect_true($gap('huntress') > $gap('ravager'), 'huntress gap larger');
            expect_true($gap('huntress') > 0.0, 'hiding helps');
        },
    ];
}
