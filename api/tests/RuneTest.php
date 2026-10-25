<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Runes;
use BloodAndBanners\Rules\Vanilla\Stats;

/** A 9×9 open board; $runes maps hex => [kind, radius]. */
function rune_battle(array $units, array $runes): array
{
    $elevations = [];
    for ($q = -4; $q <= 4; $q++) {
        for ($r = -4; $r <= 4; $r++) {
            $elevations["{$q},{$r}"] = 0;
        }
    }
    $state = vanilla_battle(vanilla_board($elevations), $units);
    foreach ($runes as $hex => [$kind, $radius]) {
        $state['board'][$hex]['object'] = $kind;
        $state['board'][$hex]['radius'] = $radius;
    }
    return $state;
}

function rune_tests(): array
{
    return [
        'a rune-of-magic board tile still gives the Rune of Focus bonus' => static function (): void {
            $state = rune_battle([], ['0,0' => ['rune-of-magic', 1]]);
            expect_same(['rune-of-focus'], Runes::coveringKinds($state, '0,0'), 'old name read as focus');
        },
        'a rune reaches radius minus one hexes, whatever the height' => static function (): void {
            $state = rune_battle([], ['0,0' => ['rune-of-warding', 1]]);
            expect_same(['rune-of-warding'], Runes::coveringKinds($state, '0,0'), 'radius 1 is its own hex');
            expect_same([], Runes::coveringKinds($state, '1,0'), 'radius 1 does not reach a neighbour');
            foreach ([2 => '1,0', 3 => '2,0', 4 => '3,0'] as $radius => $edge) {
                $state['board']['0,0']['radius'] = $radius;
                $state['board'][$edge]['elevation'] = 5;
                expect_same(['rune-of-warding'], Runes::coveringKinds($state, $edge), "radius {$radius} reaches distance " . ($radius - 1));
                expect_same([], Runes::coveringKinds($state, '4,0'), "radius {$radius} stops short of distance 4");
            }
        },

        'any unit in the area gets the bonus, the void lord included' => static function (): void {
            $state = rune_battle([vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '1,0')], ['0,0' => ['rune-of-warding', 2]]);
            expect_same(2, Stats::effective($state, 'a1', 'defense'), 'a1 on the rune: 1 + 1');
            expect_same(2, Stats::effective($state, 'b1', 'defense'), 'the enemy beside it too');
            $state['units']['b1']['hex'] = '3,0';
            expect_same(1, Stats::effective($state, 'b1', 'defense'), 'out of reach');

            $coop = coop_battle([coop_hero('h1', 'hero-a', '0,0'), vanilla_unit('m1', 'void-lord', '1,0')]);
            $coop['board']['0,0']['object'] = 'rune-of-warding';
            $coop['board']['0,0']['radius'] = 2;
            expect_same((int)$coop['units']['m1']['defense'] + 1, Stats::effective($coop, 'm1', 'defense'), 'a monster guarding the rune benefits');
        },

        'strength boosts regular attacks and focus boosts special attacks' => static function (): void {
            $state = rune_battle([vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '1,0')],
                ['0,0' => ['rune-of-strength', 1], '-1,0' => ['rune-of-focus', 2]]);
            $regular = ['attackerId' => 'a1', 'defenderId' => 'b1', 'ability' => null];
            $special = ['attackerId' => 'a1', 'defenderId' => 'b1', 'ability' => 'Fireball'];
            expect_same([['source' => 'Rune of Strength', 'amount' => 1]], Stats::breakdown($state, 'a1', 'power', $regular)['modifiers'], 'strength on a regular attack');
            expect_same([['source' => 'Rune of Focus', 'amount' => 1]], Stats::breakdown($state, 'a1', 'power', $special)['modifiers'], 'focus on a special attack');
            expect_same(2, Stats::effective($state, 'a1', 'power'), 'no attack, no power bonus');
            expect_same(2, Stats::effective($state, 'b1', 'power', ['attackerId' => 'b1', 'defenderId' => 'a1', 'ability' => null]), 'b1 is outside both areas');
        },

        'the same kind counts once and different kinds combine' => static function (): void {
            $state = rune_battle([vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '3,0')],
                ['1,0' => ['rune-of-strength', 2], '-1,0' => ['rune-of-strength', 3], '0,1' => ['rune-of-warding', 2]]);
            expect_same(['rune-of-strength', 'rune-of-warding'], Runes::coveringKinds($state, '0,0'), 'each kind once');
            expect_same(3, Stats::effective($state, 'a1', 'power', ['attackerId' => 'a1', 'defenderId' => 'b1', 'ability' => null]), 'two strength runes still +1');
            expect_same(2, Stats::effective($state, 'a1', 'defense'), 'warding adds alongside');
        },

        'dragon breath counts as a special attack for the runes' => static function (): void {
            $dice = static function (string $kind) {
                $state = breath_battle('3,0', ['defense' => 6]);
                if ($kind !== '') {
                    $state['board']['0,0']['object'] = $kind;
                }
                $state['board']['0,0']['radius'] = 1;
                $hits = breath_hits(breath_cast($state, [1, 1, 1, 1, 1, 1, 4, 4, 4, 4, 4, 4, 4, 6, 4]));
                expect_true($hits !== [], 'the breath was resolved');
                return count($hits[0]['attackFaces']);
            };
            expect_same($dice(''), $dice('rune-of-strength'), 'strength does not boost dragon breath');
            expect_same($dice('') + 1, $dice('rune-of-focus'), 'focus boosts dragon breath');
        },
    ];
}
