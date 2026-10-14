<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Schedule;

function coop_battle_tests(): array
{
    return [
        'turns run hero a, void lord, hero b, void lord and count rounds on the wrap' => static function (): void {
            $state = coop_battle([
                coop_hero('a-h', 'hero-a', '0,0'), coop_hero('b-h', 'hero-b', '1,0'), vanilla_unit('v-m1', 'void-lord', '8,0'),
            ]);
            $seen = [];
            for ($i = 0; $i < 4; $i++) {
                $seen[] = [$state['turn']['activeSeatId'], $state['turn']['round']];
                $state = coop_command($state, $state['turn']['activeSeatId'], 'endTurn')['state'];
            }
            $seen[] = [$state['turn']['activeSeatId'], $state['turn']['round']];
            expect_same([['hero-a', 1], ['void-lord', 1], ['hero-b', 1], ['void-lord', 1], ['hero-a', 2]], $seen, 'the void lord answers each hero player');
        },

        'a wiped-out hero player and the void lord response to them are skipped' => static function (): void {
            $state = coop_battle([
                coop_hero('a-h', 'hero-a', '0,0'), coop_hero('b-h', 'hero-b', null), vanilla_unit('v-m1', 'void-lord', '8,0'),
            ], 'void-lord', 1);
            $passed = coop_command($state, 'void-lord', 'endTurn')['state'];
            expect_same(['hero-a', 0, 2, 2], [$passed['turn']['activeSeatId'], $passed['turn']['slot'], $passed['turn']['round'], $passed['turn']['number']], 'hero-b and its response are skipped');
            expect_same(4, Schedule::nextTurnNumberOf($passed, 'hero-a'), 'with hero-b gone, hero-a acts every other turn');
            expect_same(3, Schedule::nextTurnNumberOf($passed, 'void-lord'), 'the void lord acts next');
        },

        'the schedule predicts each seat next turn number' => static function (): void {
            $state = coop_battle([
                coop_hero('a-h', 'hero-a', '0,0'), coop_hero('b-h', 'hero-b', '1,0'), vanilla_unit('v-m1', 'void-lord', '8,0'),
            ]);
            expect_same(2, Schedule::nextTurnNumberOf($state, 'void-lord'), 'the void lord turn follows hero a');
            expect_same(3, Schedule::nextTurnNumberOf($state, 'hero-b'), 'hero b acts after the void lord');
        },

        'hero players get five activations: both heroes and three others, more as heroes fall' => static function (): void {
            $units = [coop_hero('a-h1', 'hero-a', '0,0'), coop_hero('a-h2', 'hero-a', null)];
            foreach (range(1, 5) as $i) {
                $units[] = vanilla_unit("a-u{$i}", 'hero-a', "{$i},0");
            }
            $units[] = vanilla_unit('v-m1', 'void-lord', '8,0');
            $state = coop_battle($units);
            expect_same(5, $state['turn']['allowance'], 'a hero player has five activations');
            foreach (['a-u1', 'a-u2', 'a-u3', 'a-u4'] as $unitId) {
                $state = coop_command($state, 'hero-a', 'activateUnit', ['unitId' => $unitId])['state'];
            }
            expect_game_error('heroesActivationRequired', static fn() => coop_command($state, 'hero-a', 'activateUnit', ['unitId' => 'a-u5']));
            expect_same(1, \BloodAndBanners\Rules\Vanilla\Turn::activationsRemaining($state), 'four of five activations are spent');
            $state = coop_command($state, 'hero-a', 'activateUnit', ['unitId' => 'a-h1'])['state'];
            expect_same('void-lord', $state['turn']['activeSeatId'], 'the fifth activation spends the turn, so it passes');
        },

        'hero players cannot command an ally unit' => static function (): void {
            $state = coop_battle([coop_hero('a-h', 'hero-a', '0,0'), coop_hero('b-h', 'hero-b', '1,0'), vanilla_unit('v-m1', 'void-lord', '8,0')]);
            expect_game_error('notYourUnit', static fn() => coop_command($state, 'hero-a', 'activateUnit', ['unitId' => 'b-h']));
        },

        'the void lord activates any five monsters' => static function (): void {
            $units = [coop_hero('a-h', 'hero-a', '0,0')];
            foreach (range(1, 6) as $i) {
                $units[] = vanilla_unit("v-m{$i}", 'void-lord', ($i <= 4 ? $i + 1 : $i + 2) . ',0');
            }
            $state = coop_battle($units, 'void-lord', 1);
            expect_same(5, $state['turn']['allowance'], 'the void lord has five activations');
            foreach (range(1, 5) as $i) {
                $state = coop_command($state, 'void-lord', 'activateUnit', ['unitId' => "v-m{$i}"])['state'];
            }
            expect_game_error('noActivationsRemaining', static fn() => coop_command($state, 'void-lord', 'activateUnit', ['unitId' => 'v-m6']));
        },

        'heroes win when the last monster falls' => static function (): void {
            $state = coop_battle([
                coop_hero('a-h', 'hero-a', '0,0', ['power' => 6]),
                vanilla_unit('v-m1', 'void-lord', '1,0', ['hp' => 1, 'defense' => 0]),
            ]);
            $result = coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'a-h', 'targetUnitId' => 'v-m1'], [1, 1, 1, 1, 1, 1]);
            expect_same('completed', $result['state']['phase'], 'the match ends');
            expect_same(['reason' => 'monstersDefeated', 'winningSeatId' => null, 'winningSide' => 'heroes'], $result['state']['result'], 'the heroes win');
            expect_same('matchCompleted', $result['events'][array_key_last($result['events'])]['kind'], 'victory is announced');
        },

        'the void lord wins when the last hero falls even if army units remain' => static function (): void {
            $state = coop_battle([
                coop_hero('a-h', 'hero-a', '1,0', ['hp' => 1, 'defense' => 0]),
                vanilla_unit('a-u1', 'hero-a', '5,0'),
                vanilla_unit('v-m1', 'void-lord', '0,0', ['power' => 6]),
            ], 'void-lord', 1);
            $result = coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'v-m1', 'targetUnitId' => 'a-h'], [1, 1, 1, 1, 1, 1]);
            expect_same(['reason' => 'heroesDefeated', 'winningSeatId' => 'void-lord', 'winningSide' => 'void'], $result['state']['result'], 'the void lord wins');
        },
    ];
}
