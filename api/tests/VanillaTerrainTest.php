<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Board;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Movement;
use BloodAndBanners\Rules\Vanilla\Forced;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Terrain;
use BloodAndBanners\Rules\Vanilla\TerrainResolver;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

function terrain_unit(string $unitId, string $seatId, string $hex, array $abilities = [], array $stats = []): array
{
    return vanilla_unit($unitId, $seatId, $hex, $stats + [
        'abilities' => array_map(
            static fn(string $name): array => ['name' => $name, 'type' => 'Essence', 'description' => ''],
            $abilities
        ),
        'flags' => [],
    ]);
}

final class CountingTerrainRandom implements RandomSource
{
    public int $rolls = 0;

    public function roll(int $sides): int
    {
        $this->rolls++;
        return 1;
    }
}

function movement_option(array $options, string $destination): ?array
{
    foreach ($options as $option) {
        if ($option['destination'] === $destination) {
            return $option;
        }
    }
    return null;
}

function vanilla_terrain_tests(): array
{
    return [
        'engine boards retain painted terrain and old boards normalize to neutral ground' => static function (): void {
            $board = Board::fromScenario([
                'map' => ['hexes' => [[
                    'q' => 2, 'r' => 3, 'biome' => 'water', 'terrain' => 'open-water',
                    'elevation' => -1, 'sightBlocker' => false, 'playerStart' => 1,
                ]]],
            ]);
            expect_same(
                ['elevation' => -1, 'sightBlocker' => false, 'playerStart' => 1, 'biome' => 'water', 'terrain' => 'open-water'],
                $board['2,3'],
                'the engine must retain painted terrain from the scenario'
            );

            $old = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0]), [
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('b1', 'seat-b', '1,0'),
            ]);
            unset($old['board']['0,0']['biome'], $old['board']['0,0']['terrain']);
            $old['board']['1,0']['biome'] = 'lava';
            $old['board']['1,0']['terrain'] = 'basalt';
            $normal = MatchEngine::normalize($old);
            expect_same(['biome' => 'plains', 'terrain' => 'grass'], array_intersect_key($normal['board']['0,0'], ['biome' => true, 'terrain' => true]), 'missing paint should become neutral');
            expect_same(['biome' => 'lava', 'terrain' => 'basalt'], array_intersect_key($normal['board']['1,0'], ['biome' => true, 'terrain' => true]), 'present paint must not be overwritten');
        },

        'only the four approved painted subtypes classify as terrain rules' => static function (): void {
            foreach ([
                'open-water' => 'water',
                'ice' => 'ice',
                'poison-pits' => 'poison',
                'lava' => 'lava',
            ] as $paint => $type) {
                expect_same($type, Terrain::type(['terrain' => $paint]), "{$paint} should classify as {$type}");
            }
            foreach (['shallow-water', 'snow', 'basalt', 'darkness', 'grass'] as $paint) {
                expect_same(null, Terrain::type(['terrain' => $paint]), "{$paint} should remain ordinary terrain");
            }
        },

        'open water lowers Power while shallow water remains neutral' => static function (): void {
            $water = ['0,0' => ['biome' => 'water', 'terrain' => 'open-water']];
            $state = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0], [], $water), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 3]),
                vanilla_unit('b1', 'seat-b', '1,0'),
            ]);
            expect_same(2, Stats::effective($state, 'a1', 'power'), 'open water should cost one Power');

            $state['board']['0,0']['terrain'] = 'shallow-water';
            expect_same(3, Stats::effective($state, 'a1', 'power'), 'shallow water should not change Power');
        },

        'Flying and Lava Walker expose their terrain traits' => static function (): void {
            $state = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 0]), [
                terrain_unit('flyer', 'seat-a', '0,0', ['Flying']),
                terrain_unit('walker', 'seat-a', '1,0', ['Lava Walker']),
                vanilla_unit('b1', 'seat-b', '2,0'),
            ]);
            $flying = AbilityRegistry::get('Flying');
            $walker = AbilityRegistry::get('Lava Walker');
            expect_true($flying !== null && $walker !== null, 'both printed terrain abilities should be playable');
            expect_true(Terrain::hasTrait($state, 'flyer', 'Flying'), 'the flyer should expose Flying');
            expect_true(Terrain::hasTrait($state, 'walker', 'Lava Walker'), 'the walker should expose Lava Walker');
            expect_true($flying->ignoresTerrain('water', 'voluntary'), 'Flying ignores open water');
            expect_true($flying->ignoresTerrain('ice', 'slip'), 'Flying ignores ice');
            expect_true($flying->ignoresTerrain('lava', 'voluntary'), 'Flying crosses lava voluntarily');
            expect_true(!$flying->ignoresTerrain('lava', 'push'), 'Flying does not ignore forced lava entry');
            expect_true(!$flying->ignoresTerrain('lava', 'teleport'), 'Flying does not ignore teleported lava entry');
            expect_true($walker->ignoresTerrain('lava', 'teleport'), 'Lava Walker ignores lava for every movement kind');
            expect_true(!$walker->ignoresTerrain('water', 'voluntary'), 'Lava Walker does not ignore open water');
        },

        'Lava Walker defense changes only for typed fire and water attacks' => static function (): void {
            $state = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0]), [
                terrain_unit('walker', 'seat-a', '0,0', ['Lava Walker'], ['defense' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0'),
            ]);
            expect_same(3, Stats::effective($state, 'walker', 'defense', ['damageType' => 'fire']), 'fire grants two Defense');
            expect_same(0, Stats::effective($state, 'walker', 'defense', ['damageType' => 'water']), 'water removes two Defense and floors at zero');
            expect_same(1, Stats::effective($state, 'walker', 'defense'), 'untyped damage leaves Defense alone');
            expect_same(1, Stats::effective($state, 'b1', 'defense', ['damageType' => 'fire']), 'other units get no elemental modifier');
        },

        'typed strikes roll the Lava Walker elemental defense modifier' => static function (): void {
            $state = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 0]), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 1]),
                terrain_unit('walker', 'seat-b', '1,0', ['Lava Walker'], ['defense' => 1, 'hp' => 9]),
                vanilla_unit('b2', 'seat-b', '2,0'),
            ]);

            $fireEvents = [];
            (new StepRunner(new SequenceRandom([6, 6, 6, 6]), 0))->run(
                $state,
                [StepRunner::strike('a1', 'walker', 1, null, false, 'fire')],
                $fireEvents
            );
            expect_same(3, count($fireEvents[0]['payload']['defenseFaces']), 'fire should roll base Defense plus two');
            expect_same('fire', $fireEvents[0]['payload']['damageType'] ?? null, 'the event should retain fire typing');

            $waterEvents = [];
            (new StepRunner(new SequenceRandom([6]), 0))->run(
                $state,
                [StepRunner::strike('a1', 'walker', 1, null, false, 'water')],
                $waterEvents
            );
            expect_same([], $waterEvents[0]['payload']['defenseFaces'], 'water should floor Defense at zero');
            expect_same('water', $waterEvents[0]['payload']['damageType'] ?? null, 'the event should retain water typing');

            $ordinaryEvents = [];
            (new StepRunner(new SequenceRandom([6, 6]), 0))->run(
                $state,
                [StepRunner::strike('a1', 'b2', 1, null, false, 'fire')],
                $ordinaryEvents
            );
            expect_same(1, count($ordinaryEvents[0]['payload']['defenseFaces']), 'fire should not modify an ordinary defender');
        },

        'canonical options apply water costs stops and climb rules' => static function (): void {
            $paint = [
                '1,0' => ['biome' => 'water', 'terrain' => 'open-water'],
            ];
            $state = vanilla_battle(vanilla_board(vanilla_line(3), [], $paint), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 4]),
                vanilla_unit('b1', 'seat-b', '2,0'),
            ]);
            $water = movement_option(Movement::options($state, 'a1', 4), '1,0');
            expect_same(['1,0'], $water['path'] ?? null, 'the water path enters exactly one hex');
            expect_same(1, $water['cost'] ?? null, 'entering water costs one');
            expect_same(true, $water['hazards'][0]['stops'] ?? null, 'water ends voluntary movement');
            expect_same(null, movement_option(Movement::options($state, 'a1', 4), '2,0'), 'movement cannot continue after water entry');

            $state['units']['a1']['hex'] = '1,0';
            $state['units']['b1']['hex'] = null;
            expect_same(2, movement_option(Movement::options($state, 'a1', 4), '2,0')['cost'] ?? null, 'leaving water costs two');

            $climb = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 1], [], $paint), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 3]),
                vanilla_unit('b1', 'seat-b', null),
            ]);
            expect_same(null, Movement::canonical($climb, 'a1', '1,0', 3), 'water counts one level higher for climb legality');
            $climb['units']['a1'] = terrain_unit('a1', 'seat-a', '0,0', ['Flying'], ['move' => 3]);
            expect_true(Movement::canonical($climb, 'a1', '1,0', 3) !== null, 'Flying ignores water and may climb two levels');
        },

        'poison stops while lava accumulates per hex and Flying crosses but cannot land' => static function (): void {
            $poisonPaint = ['1,0' => ['biome' => 'darkness', 'terrain' => 'poison-pits']];
            $poison = vanilla_battle(vanilla_board(vanilla_line(3), [], $poisonPaint), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 4]), vanilla_unit('b1', 'seat-b', null),
            ]);
            expect_true(Movement::canonical($poison, 'a1', '1,0', 4) !== null, 'poison itself is reachable');
            expect_same(null, Movement::canonical($poison, 'a1', '2,0', 4), 'voluntary movement stops in poison');

            $lavaPaint = [
                '1,0' => ['biome' => 'lava', 'terrain' => 'lava'],
                '2,0' => ['biome' => 'lava', 'terrain' => 'lava'],
            ];
            $lava = vanilla_battle(vanilla_board(vanilla_line(4), [], $lavaPaint), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 4]), vanilla_unit('b1', 'seat-b', null),
            ]);
            $crossing = Movement::canonical($lava, 'a1', '3,0', 4);
            expect_same(['1,0', '2,0', '3,0'], $crossing['path'] ?? null, 'the exact crossing path is published');
            expect_same(10, array_sum(array_column($crossing['hazards'] ?? [], 'dice')), 'two lava hexes guarantee ten damage dice');

            $lava['units']['a1'] = terrain_unit('a1', 'seat-a', '0,0', ['Flying'], ['move' => 4]);
            $flight = Movement::canonical($lava, 'a1', '3,0', 4);
            expect_same([], $flight['hazards'] ?? null, 'Flying crosses lava without a hazard');
            expect_same(null, Movement::canonical($lava, 'a1', '1,0', 4), 'Flying cannot voluntarily end on lava');
        },

        'Flying ignores ice and may descend any number of levels' => static function (): void {
            $paint = ['1,0' => ['biome' => 'ice', 'terrain' => 'ice']];
            $state = vanilla_battle(vanilla_board(['0,0' => 4, '1,0' => 0], [], $paint), [
                terrain_unit('a1', 'seat-a', '0,0', ['Flying'], ['move' => 1]), vanilla_unit('b1', 'seat-b', null),
            ]);
            $option = Movement::canonical($state, 'a1', '1,0', 1);
            expect_same(['1,0'], $option['path'] ?? null, 'Flying may descend four levels in one step');
            expect_same([], $option['hazards'] ?? null, 'Flying ignores ice entry and forced sliding');
        },

        'ice projects forced continuation and ties choose a stable path' => static function (): void {
            $icePaint = [
                '1,0' => ['biome' => 'ice', 'terrain' => 'ice'],
                '2,0' => ['biome' => 'ice', 'terrain' => 'ice'],
            ];
            $ice = vanilla_battle(vanilla_board(vanilla_line(4), [], $icePaint), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 3]), vanilla_unit('b1', 'seat-b', null),
            ]);
            $slide = Movement::canonical($ice, 'a1', '3,0', 3);
            expect_same(['1,0', '2,0', '3,0'], $slide['path'] ?? null, 'entering ice projects the whole straight slide');
            expect_true(count(array_filter($slide['hazards'] ?? [], static fn(array $hazard): bool => $hazard['forced'])) >= 1, 'the projected slide is marked forced');
            expect_same(null, Movement::canonical($ice, 'a1', '1,0', 3), 'an ice entry is not an optional stopping point');

            $tie = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0, '0,1' => 0, '1,1' => 0]), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 2]), vanilla_unit('b1', 'seat-b', null),
            ]);
            expect_same(['0,1', '1,1'], Movement::canonical($tie, 'a1', '1,1', 2)['path'] ?? null, 'equal routes use stable hex-key ordering');
        },

        'movement search stays inside the effective Move radius on Home Map' => static function (): void {
            $definition = json_decode((string)file_get_contents(dirname(__DIR__) . '/scenarios/home-map.json'), true, flags: JSON_THROW_ON_ERROR);
            $state = vanilla_battle(Board::fromScenario($definition), [
                vanilla_unit('a1', 'seat-a', '3,3', ['move' => 2]), vanilla_unit('b1', 'seat-b', '28,26'),
            ]);
            $options = Movement::options($state, 'a1', 2);
            expect_true(count($options) <= 18, 'a Move 2 search should remain local on a 960-hex board');
            foreach ($options as $option) {
                expect_true($option['cost'] <= 2, 'every published option stays within budget');
            }
        },

        'move commands require the exact canonical path for terrain routes' => static function (): void {
            $paint = ['1,0' => ['biome' => 'lava', 'terrain' => 'lava']];
            $state = vanilla_battle(vanilla_board(vanilla_line(3), [], $paint), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 3, 'hp' => 20]), vanilla_unit('b1', 'seat-b', '2,0'),
            ]);
            expect_game_error('movementPathRequired', static fn() => vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '1,0']));
            foreach ([['2,0'], ['1,0', '1,0'], ['1,0', '0,0', '1,0']] as $path) {
                expect_game_error('invalidMovementPath', static fn() => vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'path' => $path]));
            }
            $random = new CountingTerrainRandom();
            try {
                (new MatchEngine(test_catalog()))->apply($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'path' => ['1,0', '0,0', '1,0']], $random);
            } catch (\BloodAndBanners\Game\GameServiceException) {
            }
            expect_same(0, $random->rolls, 'a repeated hazardous path is rejected before random is consumed');

            $flying = $state;
            $flying['units']['a1'] = terrain_unit('a1', 'seat-a', '0,0', ['Flying'], ['move' => 3]);
            expect_game_error('invalidTerrainDestination', static fn() => vanilla_command($flying, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'path' => ['1,0']]));
        },

        'exact paths move and destination-only neutral commands remain compatible' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 3]), vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            $exact = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'path' => ['1,0', '2,0']]);
            expect_same('2,0', $exact['state']['units']['a1']['hex'], 'the submitted canonical path moves the unit');
            expect_same(['1,0', '2,0'], $exact['events'][0]['payload']['path'] ?? null, 'the movement event keeps the exact path');

            $legacy = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '2,0']);
            expect_same('2,0', $legacy['state']['units']['a1']['hex'], 'destination-only neutral movement remains accepted');

            $stale = $state;
            $stale['units']['b1']['hex'] = '1,0';
            expect_game_error('invalidMovementPath', static fn() => vanilla_command($stale, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'path' => ['1,0', '2,0']]));
        },

        'terrain entry resolves water lava and poison through one pipeline' => static function (): void {
            $paint = [
                '1,0' => ['biome' => 'water', 'terrain' => 'open-water'],
                '2,0' => ['biome' => 'lava', 'terrain' => 'lava'],
                '3,0' => ['biome' => 'darkness', 'terrain' => 'poison-pits'],
                '4,0' => ['biome' => 'water', 'terrain' => 'shallow-water'],
            ];
            $state = vanilla_battle(vanilla_board(vanilla_line(5), [], $paint), [
                terrain_unit('a1', 'seat-a', '0,0', ['Lava Walker'], ['hp' => 20]), vanilla_unit('b1', 'seat-b', null),
            ]);
            $events = [];
            $water = TerrainResolver::enter($state, 'a1', '0,0', '1,0', 'voluntary', [1, 0], new SequenceRandom([1]), $events);
            expect_same(true, $water['stop'], 'open water stops voluntary movement');
            expect_same(1, $water['state']['units']['a1']['wounds'], 'Lava Walker takes one open-water die');
            expect_same(['terrainEntered', 'terrainDamage', 'movementStopped'], array_column($events, 'kind'), 'water entry has stable events');

            $events = [];
            $lava = TerrainResolver::enter($water['state'], 'a1', '1,0', '2,0', 'push', [1, 0], new SequenceRandom([1, 4, 4, 4, 4]), $events);
            expect_same(1, $lava['state']['units']['a1']['wounds'], 'Lava Walker ignores lava damage and retains only its water wound');
            expect_same(['terrainEntered', 'terrainIgnored'], array_column($events, 'kind'), 'lava immunity is explicit');

            $events = [];
            $poison = TerrainResolver::enter($lava['state'], 'a1', '2,0', '3,0', 'push', [1, 0], new SequenceRandom([]), $events);
            expect_same(false, $poison['stop'], 'forced poison entry continues');
            expect_true(UnitConditions::has($poison['state'], 'a1', 'Poisoned'), 'poison entry applies Poisoned');
            expect_same(['terrainEntered', 'conditionApplied'], array_column($events, 'kind'), 'poison applies through the condition pipeline');

            $events = [];
            $neutral = TerrainResolver::enter($poison['state'], 'a1', '3,0', '4,0', 'voluntary', [1, 0], new SequenceRandom([]), $events);
            expect_same([], $events, 'shallow water is completely neutral');
            expect_same(false, $neutral['stop'], 'shallow water does not stop movement');
        },

        'voluntary lava resolves every entered hex and stops immediately on lethal entry' => static function (): void {
            $paint = [
                '1,0' => ['biome' => 'lava', 'terrain' => 'lava'],
                '2,0' => ['biome' => 'lava', 'terrain' => 'lava'],
            ];
            $state = vanilla_battle(vanilla_board(vanilla_line(4), [], $paint), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 3, 'hp' => 1]),
                vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            $random = new CountingTerrainRandom();
            $result = (new MatchEngine(test_catalog()))->apply(
                $state,
                'seat-a',
                'moveUnit',
                ['unitId' => 'a1', 'path' => ['1,0', '2,0']],
                $random
            );
            expect_same(5, $random->rolls, 'only the first lava hex rolls after lethal entry');
            expect_same(['terrainEntered', 'terrainDamage', 'unitDefeated', 'matchCompleted'], array_column($result['events'], 'kind'), 'later entry and unitMoved never occur');
            expect_same('completed', $result['state']['phase'], 'lethal terrain completes the match');
            expect_same(null, $result['state']['pending'], 'completion leaves no pending state');
        },

        'push and teleport apply Lava Walker water weakness on every arrival' => static function (): void {
            $paint = [
                '1,0' => ['biome' => 'water', 'terrain' => 'open-water'],
                '2,0' => ['biome' => 'water', 'terrain' => 'open-water'],
            ];
            $state = vanilla_battle(vanilla_board(vanilla_line(4), [], $paint), [
                terrain_unit('a1', 'seat-a', '0,0', ['Lava Walker'], ['hp' => 9]), vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            $events = [];
            $pushed = Forced::push($state, 'a1', [1, 0], 2, 'b1', new SequenceRandom([1, 1]), $events);
            expect_same(2, $pushed['units']['a1']['wounds'], 'each pushed water hex rolls one damage die');
            expect_same(2, count(array_filter($events, static fn(array $event): bool => $event['kind'] === 'terrainDamage')), 'both pushed entries are narrated');

            $teleportState = $state;
            $teleportEvents = [];
            (new StepRunner(new SequenceRandom([1]), 0))->run(
                $teleportState,
                [StepRunner::teleport('b1', 'a1', '1,0')],
                $teleportEvents
            );
            expect_same(['terrainEntered', 'terrainDamage', 'unitTeleported'], array_column($teleportEvents, 'kind'), 'teleport arrival uses the same terrain pipeline');
        },
    ];
}
