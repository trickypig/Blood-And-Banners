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
            $state = coop_battle(ai_eval_units(0), 'void-lord');
            $sim = new Simulator(new MatchEngine(test_catalog()), 1000);
            expect_same([], $sim->outcomes($state, 'void-lord', 'attackUnit', ['unitId' => 'v-m1', 'targetUnitId' => 'nobody'], 2, 1), 'illegal');
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
