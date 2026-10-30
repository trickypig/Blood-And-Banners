<?php
declare(strict_types=1);

use BloodAndBanners\Ai\BiomeMap;
use BloodAndBanners\Ai\HordeBuilder;
use BloodAndBanners\Ai\Personalities;
use BloodAndBanners\Ai\SeededRandom;
use BloodAndBanners\Rules\Vanilla\Board;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Modes\CoopSetup;
use BloodAndBanners\Rules\Vanilla\Terrain;

/** @return list<string> built-in co-op scenario names */
function ai_coop_scenarios(): array
{
    $names = [];
    foreach (glob(dirname(__DIR__) . '/scenarios/*.json') as $path) {
        $definition = json_decode((string)file_get_contents($path), true);
        if (in_array('coop', $definition['modeCompatibility'] ?? [], true)) {
            $names[] = basename($path, '.json');
        }
    }
    return $names;
}

function ai_catalog(): Catalog
{
    static $catalog = null;
    return $catalog ??= new Catalog();
}

/** A co-op prep state (Void Lord seated, nothing placed yet) on the given board. */
function ai_prep_on_board(array $board, int $heroes, array $coop): array
{
    $seats = [];
    for ($i = 1; $i <= $heroes; $i++) {
        $seats[] = "hero-{$i}";
    }
    return (new MatchEngine(ai_catalog()))->start(
        array_merge($seats, ['void-lord']), $board, new SeededRandom(1), 'coop', ['voidLordSeatId' => 'void-lord', 'coop' => $coop]
    )['state'];
}

function ai_prep_state(string $scenario, int $heroes): array
{
    static $cache = [];
    if (!isset($cache["{$scenario}:{$heroes}"])) {
        $definition = json_decode((string)file_get_contents(dirname(__DIR__) . "/scenarios/{$scenario}.json"), true);
        $cache["{$scenario}:{$heroes}"] = ai_prep_on_board(Board::fromScenario($definition), $heroes, $definition['coop'] ?? []);
    }
    return $cache["{$scenario}:{$heroes}"];
}

/** monster name => hexes, from the Void Lord's legal placeMonster actions. */
function ai_placeable(array $state): array
{
    $placeable = [];
    foreach ((new MatchEngine(ai_catalog()))->legalActions($state, 'void-lord') as $action) {
        if ($action['kind'] === 'placeMonster') {
            $placeable[$action['monster']] = $action['targetHexes'];
        }
    }
    return $placeable;
}

function ai_plan(array $state, string $personality, int $seed = 1): array
{
    $heroes = count($state['sides']['heroes']['seatIds']);
    return (new HordeBuilder(ai_catalog()))->plan(
        $state['board'], ai_placeable($state), (int)$state['setup']['budget'], $heroes, Personalities::get($personality), $seed
    );
}

/** Applies the plan through the real engine and confirms; returns the confirmed state. */
function ai_apply_plan(array $state, array $plan): array
{
    $engine = new MatchEngine(ai_catalog());
    $random = new SeededRandom(1);
    foreach ($plan as $placement) {
        $state = $engine->apply($state, 'void-lord', 'placeMonster', $placement, $random)['state'];
    }
    return $engine->apply($state, 'void-lord', 'confirmSetup', [], $random)['state'];
}

function ai_plan_cost(array $plan): int
{
    return array_sum(array_map(static fn (array $p): int => CoopSetup::cost(ai_catalog()->monster($p['monster'])), $plan));
}

function ai_near_cover(array $board, string $hex): bool
{
    foreach (Hex::neighbors($hex, $board) as $n) {
        if (($board[$n]['sightBlocker'] ?? false) || ($board[$n]['impassable'] ?? false) || Terrain::type($board[$n]) === 'water') {
            return true;
        }
    }
    return false;
}

function ai_horde_tests(): array
{
    return [
        'each personality fields a legal horde on every built-in co-op map' => static function (): void {
            foreach (ai_coop_scenarios() as $scenario) {
                foreach ([1, 4] as $heroes) {
                    $state = ai_prep_state($scenario, $heroes);
                    // A small spawn zone caps what can be spent: at most a boss (4 points) per free hex.
                    $hexes = array_unique(array_merge(...array_values(ai_placeable($state))));
                    $budget = (int)$state['setup']['budget'];
                    $reachable = min($budget, 4 * count($hexes));
                    foreach (array_keys(Personalities::all()) as $id) {
                        $plan = ai_plan($state, $id);
                        $done = ai_apply_plan($state, $plan);
                        expect_true($done['setup']['confirmed'], "{$scenario}/{$heroes}/{$id} confirms");
                        $spent = ai_plan_cost($plan);
                        expect_true($spent <= $budget, "{$scenario}/{$heroes}/{$id} within budget ({$spent}/{$budget})");
                        expect_true($spent >= $reachable - 4, "{$scenario}/{$heroes}/{$id} spends the budget ({$spent}/{$reachable} of {$budget})");
                    }
                }
            }
        },
        'awkward custom maps still get a horde' => static function (): void {
            $coop = ['basePoints' => 12, 'pointsPerHeroPlayer' => 30];
            $single = coop_board();
            foreach ($single as $key => $hex) {
                $single[$key]['spawnZone'] = in_array($key, ['7,4', '8,4'], true) ? 'void' : null; // the Void Portal takes one of the two
            }
            $oneBiome = coop_board();
            foreach ($oneBiome as $key => $hex) {
                $oneBiome[$key]['biome'] = 'sand';
                $oneBiome[$key]['terrain'] = 'sand';
            }
            $boards = ['one free spawn hex' => $single, 'all plains' => coop_board(), 'single biome' => $oneBiome];
            foreach ($boards as $label => $board) {
                $state = ai_prep_on_board($board, 2, $coop);
                foreach (array_keys(Personalities::all()) as $id) {
                    $plan = ai_plan($state, $id);
                    expect_true($plan !== [], "{$label}/{$id} is not empty");
                    expect_true(ai_apply_plan($state, $plan)['setup']['confirmed'], "{$label}/{$id} confirms");
                }
            }
        },
        'water-bound creatures land only on water' => static function (): void {
            $state = ai_prep_state('incursion-lakeheart', 2);
            $seen = 0;
            foreach (array_keys(Personalities::all()) as $id) {
                foreach ([1, 2, 3] as $seed) {
                    foreach (ai_plan($state, $id, $seed) as $placement) {
                        if (in_array($placement['monster'], ['Kraken', 'Kraken Tentacle (Greater)', 'Kraken Tentacle (Lesser)'], true)) {
                            $seen++;
                            expect_true(in_array($state['board'][$placement['hex']]['terrain'], ['open-water', 'shallow-water'], true), "{$placement['monster']} on water");
                        }
                    }
                }
            }
            expect_true($seen > 0, 'some water creatures were fielded');
        },
        'the same game seed gives the same horde, another seed varies it' => static function (): void {
            $state = ai_prep_state('void-reaches', 2);
            expect_same(ai_plan($state, 'hollowKing', 1), ai_plan($state, 'hollowKing', 1), 'seed 1 repeats');
            $distinct = [];
            foreach ([1, 2, 3, 4, 5] as $seed) {
                $distinct[serialize(ai_plan($state, 'hollowKing', $seed))] = true;
            }
            expect_true(count($distinct) >= 2, 'seeds vary the horde');
        },
        'personalities build recognisably different hordes' => static function (): void {
            $state = ai_prep_state('void-reaches', 2);
            $board = $state['board'];
            $portal = null;
            foreach ($board as $key => $hex) {
                if ($hex['voidPortal']) {
                    $portal = (string)$key;
                }
            }
            $stats = [];
            foreach (array_keys(Personalities::all()) as $id) {
                $cost = $creatures = $distance = $cover = 0;
                $biomes = [];
                foreach ([1, 2, 3, 4, 5] as $seed) {
                    foreach (ai_plan($state, $id, $seed) as $placement) {
                        $monster = ai_catalog()->monster($placement['monster']);
                        $creatures++;
                        $cost += CoopSetup::cost($monster);
                        $distance += Hex::distance($placement['hex'], $portal);
                        $cover += ai_near_cover($board, $placement['hex']) ? 1 : 0;
                        $biomes[$monster['biome']] = true;
                    }
                }
                $stats[$id] = ['cost' => $cost / $creatures, 'distance' => $distance / $creatures, 'cover' => $cover / $creatures, 'biomes' => count($biomes)];
            }
            expect_true($stats['ravager']['cost'] < $stats['warden']['cost'], 'ravager swarms cheaper creatures than warden');
            expect_true($stats['warden']['distance'] < $stats['ravager']['distance'], 'warden sits nearer the portal');
            foreach (['ravager', 'warden', 'hollowKing'] as $other) {
                expect_true($stats['huntress']['cover'] > $stats[$other]['cover'], "huntress hugs cover more than {$other}");
            }
            expect_true($stats['hollowKing']['biomes'] >= 3, 'hollow king mixes three or more biomes');
        },
        'the horde ignores hero picks' => static function (): void {
            $a = ai_prep_state('void-reaches', 2);
            $b = $a;
            $a['draft']['heroesBySeat'] = [];
            $b['draft']['heroesBySeat'] = ['hero-1' => ['Aldric', 'Brynn', 'Cael'], 'hero-2' => ['Dara']];
            expect_same(ai_placeable($a), ai_placeable($b), 'the legal list is the same');
            foreach (array_keys(Personalities::all()) as $id) {
                expect_same(ai_plan($a, $id, 3), ai_plan($b, $id, 3), "{$id} plans the same");
            }
        },
        'mostly on theme' => static function (): void {
            $state = ai_prep_state('void-reaches', 2);
            $on = $all = 0;
            foreach (ai_plan($state, 'ravager') as $placement) {
                $hex = $state['board'][$placement['hex']];
                $all++;
                $on += in_array(ai_catalog()->monster($placement['monster'])['biome'], BiomeMap::monsterBiomes($hex['biome'], $hex['terrain']), true) ? 1 : 0;
            }
            expect_true($all > 0 && $on / $all >= 0.8, "on-theme share {$on}/{$all}");
        },
    ];
}
