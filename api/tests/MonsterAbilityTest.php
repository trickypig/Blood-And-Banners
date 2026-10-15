<?php
declare(strict_types=1);

use BloodAndBanners\Game\GameService;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Movement;
use BloodAndBanners\Rules\Vanilla\Stats;

/** Use the shipped monster cards so tests catch catalog/handler integration errors. */
function monster_unit(string $id, string $name, ?string $hex, array $stats = []): array
{
    $monster = (new Catalog())->monster($name);
    if ($monster === null) {
        throw new RuntimeException("Missing test monster {$name}");
    }
    return vanilla_unit($id, 'void-lord', $hex, $stats + $monster + ['side' => 'void']);
}

function monster_ability_tests(): array
{
    return [
        'pack tactics counts other living allied wolves within two hexes and caps at two' => static function (): void {
            $state = coop_battle([
                monster_unit('wolf', 'Ice Wolf', '0,0'),
                monster_unit('near', 'Dire Wolf', '1,0'),
                monster_unit('edge', 'Ice Wolf', '2,0'),
                monster_unit('extra', 'Dire Wolf', '0,1'),
                monster_unit('far', 'Dire Wolf', '3,0'),
                monster_unit('dead', 'Ice Wolf', null),
                monster_unit('enemy', 'Ice Wolf', '1,1', ['side' => 'heroes', 'seatId' => 'hero-a']),
                monster_unit('ogre', 'Ogre', '0,2'),
            ]);
            expect_same(4, Stats::effective($state, 'wolf', 'power'), 'three nearby wolves grant the capped +2');
            $state['units']['near']['hex'] = null;
            $state['units']['extra']['hex'] = null;
            expect_same(3, Stats::effective($state, 'wolf', 'power'), 'the wolf at distance two grants +1');
            $state['units']['edge']['hex'] = '4,0';
            expect_same(2, Stats::effective($state, 'wolf', 'power'), 'self, enemies, dead wolves, other species and distant wolves grant nothing');
        },
        'shark instinct adds power only against a wounded target' => static function (): void {
            $state = coop_battle([
                monster_unit('shark', 'Sahuagin', '0,0'),
                coop_hero('hero', 'hero-a', '1,0', ['wounds' => 1]),
            ]);
            $state['board']['0,0']['terrain'] = 'shallow-water';
            $context = ['attackerId' => 'shark', 'defenderId' => 'hero'];
            expect_same(4, Stats::effective($state, 'shark', 'power', $context), 'a wounded enemy grants +1');
            expect_same(3, Stats::effective($state, 'shark', 'power'), 'no target means no bonus');
            $state['units']['hero']['wounds'] = 0;
            expect_same(3, Stats::effective($state, 'shark', 'power', $context), 'full health grants nothing');
        },
        'outrage uses each hydra printed bonus at exactly one remaining hp' => static function (): void {
            foreach ([['Marsh Hydra', 2], ['Void Hydra', 1]] as [$name, $bonus]) {
                $unit = monster_unit('hydra', $name, '0,0');
                $state = coop_battle([$unit]);
                $base = $unit['power'];
                expect_same($base, Stats::effective($state, 'hydra', 'power'), 'an unwounded hydra gets no bonus');
                $state['units']['hydra']['wounds'] = $unit['hp'] - 1;
                expect_same($base + $bonus, Stats::effective($state, 'hydra', 'power'), "{$name} gets its printed bonus");
                $state['units']['hydra']['wounds']--;
                expect_same($base, Stats::effective($state, 'hydra', 'power'), 'two remaining hp grants nothing');
            }
        },
        'frightening presence weakens adjacent nonhero enemies across allied hero seats' => static function (): void {
            $state = coop_battle([
                monster_unit('krampus', 'Krampus', '0,0'),
                vanilla_unit('troop', 'hero-a', '1,0', ['power' => 2]),
                vanilla_unit('ally-troop', 'hero-b', '0,1', ['power' => 1]),
                coop_hero('hero', 'hero-a', '1,-1', ['power' => 3]),
                monster_unit('ogre', 'Ogre', '-1,0'),
                vanilla_unit('far', 'hero-a', '2,0'),
            ]);
            expect_same(1, Stats::effective($state, 'troop', 'power'), 'an adjacent enemy loses one power even when attacking another unit');
            expect_same(0, Stats::effective($state, 'ally-troop', 'power'), 'the other hero seat is also affected');
            expect_same(3, Stats::effective($state, 'hero', 'power'), 'heroes are immune');
            expect_same($state['units']['ogre']['power'], Stats::effective($state, 'ogre', 'power'), 'allied monsters are immune');
            expect_same(2, Stats::effective($state, 'far', 'power'), 'distance two is out of range');
            $state['units']['krampus']['hex'] = null;
            expect_same(2, Stats::effective($state, 'troop', 'power'), 'a defeated krampus has no aura');
        },
        'ogre rivalry penalizes adjacency once and only for other living allied ogres' => static function (): void {
            $state = coop_battle([
                monster_unit('ogre', 'Ogre', '0,0'),
                monster_unit('other', 'Ogre', '1,0'),
                monster_unit('third', 'Ogre', '0,1'),
                monster_unit('enemy', 'Ogre', '-1,0', ['side' => 'heroes', 'seatId' => 'hero-a']),
            ]);
            $base = $state['units']['ogre']['power'];
            expect_same($base - 1, Stats::effective($state, 'ogre', 'power'), 'several ogres apply only one penalty');
            $state['units']['other']['hex'] = '2,0';
            $state['units']['third']['hex'] = null;
            expect_same($base, Stats::effective($state, 'ogre', 'power'), 'self, enemies, distant and defeated ogres do not count');
        },
        'stoneskin and hollow bones defend against distant attacks but not adjacent attacks or untyped damage' => static function (): void {
            foreach (['Stonekin Brute', 'Stonekin Mauler', 'Stonekin Boulderfist', 'Stoneking', 'Stray'] as $name) {
                $state = coop_battle([
                    monster_unit('monster', $name, '0,0'),
                    coop_hero('archer', 'hero-a', '2,0', ['range' => 4]),
                ]);
                $base = $state['units']['monster']['defense'];
                $context = ['attackerId' => 'archer', 'defenderId' => 'monster'];
                expect_same($base + 2, Stats::effective($state, 'monster', 'defense', $context), "{$name} gets +2 against an attack at distance two");
                expect_same($base, Stats::effective($state, 'monster', 'defense'), 'the card does not show an unconditional bonus');
                $state['units']['archer']['hex'] = '1,0';
                expect_same($base, Stats::effective($state, 'monster', 'defense', $context), 'an adjacent archer does not count as ranged');
            }
        },
        'monster modifiers change the actual attack and defense dice' => static function (): void {
            $state = coop_battle([
                monster_unit('shark', 'Sahuagin', '0,0', ['range' => 3]),
                monster_unit('stray', 'Stray', '2,0', ['seatId' => 'hero-a', 'side' => 'heroes', 'isHero' => true, 'wounds' => 1, 'hp' => 20]),
                coop_hero('other-hero', 'hero-b', '8,0'),
            ], 'void-lord');
            $state['board']['0,0']['terrain'] = 'shallow-water';
            $result = coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'shark', 'targetUnitId' => 'stray'], array_fill(0, 20, 6));
            $attack = array_values(array_filter($result['events'], static fn(array $event): bool => $event['kind'] === 'attackResolved'))[0]['payload'];
            expect_same(4, count($attack['attackFaces']), 'shark instinct adds an attack die');
            expect_same($state['units']['stray']['defense'] + 2, count($attack['defenseFaces']), 'hollow bones adds two defense dice');
        },
        'new monster setup enables supported essences and leaves conflicting or unsupported abilities inactive' => static function (): void {
            $engine = new MatchEngine(new Catalog());
            $state = $engine->start(['hero-a', 'void-lord'], coop_board(), new SequenceRandom([15]), 'coop', ['voidLordSeatId' => 'void-lord'])['state'];
            foreach ([['Marsh Hydra', '7,0'], ['Pyromancer', '7,1'], ['Void Dragon', '7,2']] as [$name, $hex]) {
                $state = $engine->apply($state, 'void-lord', 'placeMonster', ['monster' => $name, 'hex' => $hex], new SequenceRandom([]))['state'];
            }
            expect_same(['Multiple Heads', 'Poisonous Bite', 'Outrage'], array_column($state['units']['v-m1']['abilities'], 'name'), 'the hydra gains its supported essences');
            expect_same([], $state['units']['v-m1']['inactiveAbilities'], 'all marsh hydra abilities are now supported');
            expect_same(['Fireball'], array_column($state['units']['v-m2']['abilities'], 'name'), 'pyromancer gains its own fireball');
            expect_same([], $state['units']['v-m2']['inactiveAbilities'], 'its own fireball is supported');
            expect_same(['Flying'], array_column($state['units']['v-m3']['abilities'], 'name'), 'the dragon gains flying');
            $saved = $state;
            $saved['units']['v-m3']['inactiveAbilities'] = $saved['units']['v-m3']['abilities'];
            $saved['units']['v-m3']['abilities'] = [];
            expect_same([], MatchEngine::normalize($saved)['units']['v-m3']['abilities'], 'loading a previous match never enables new abilities');
        },
        'a summoned void dragon uses shared flying movement over water ice and lava' => static function (): void {
            $engine = new MatchEngine(new Catalog());
            $setup = $engine->start(['hero-a', 'void-lord'], coop_board(), new SequenceRandom([15]), 'coop', ['voidLordSeatId' => 'void-lord'])['state'];
            $dragon = $engine->apply($setup, 'void-lord', 'placeMonster', ['monster' => 'Void Dragon', 'hex' => '7,0'], new SequenceRandom([]))['state']['units']['v-m1'];
            $dragon['hex'] = '0,0';
            $state = coop_battle([$dragon, coop_hero('hero', 'hero-a', '8,0')], 'void-lord');
            $state['board']['1,0']['terrain'] = 'open-water';
            $state['board']['2,0']['terrain'] = 'ice';
            $state['board']['3,0']['terrain'] = 'lava';
            $option = Movement::canonical($state, 'v-m1', '4,0', 4);
            expect_true($option !== null, 'flying crosses water and lava and does not slide on ice');
            expect_same(4, $option['cost'], 'flying pays no extra water cost');
            expect_same([], $option['hazards'], 'crossing those tiles is safe in voluntary flight');
            expect_same(null, Movement::canonical($state, 'v-m1', '3,0', 4), 'shared flying rules forbid landing on lava');
            $state['board']['1,0']['terrain'] = 'poison-pits';
            expect_same(null, Movement::canonical($state, 'v-m1', '4,0', 4), 'shared flying rules still stop movement on poison pits');
            $poison = Movement::canonical($state, 'v-m1', '1,0', 4);
            expect_same('poison', $poison['hazards'][0]['terrain'], 'poison entry is still projected as a hazard');
        },
        'setup and unit projections distinguish supported monster abilities from same-name hero attacks' => static function (): void {
            [$db, $auth] = test_services();
            $games = new GameService($db, null, new ScriptedRandom(), new MatchEngine(new Catalog()));
            $host = $auth->createGuest('monster-host');
            $guest = $auth->createGuest('monster-guest');
            $created = $games->createGame($host['identityId'], 'coop', 'Void Reaches');
            $joined = $games->joinGame($created['gameId'], $guest['identityId']);
            $match = ['db' => $db, 'games' => $games, 'gameId' => $created['gameId'],
                'host' => ['identity' => $host['identityId'], 'token' => $created['seatToken'], 'seat' => $created['viewer']['seatId']],
                'hero1' => ['identity' => $guest['identityId'], 'token' => $joined['seatToken'], 'seat' => $joined['viewer']['seatId']]];
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_ready_all($match);
            $started = $games->startGame($match['gameId'], $host['identityId'], $created['seatToken']);
            $roster = array_column($started['setup']['roster'], null, 'name');
            expect_same(true, $roster['Ice Wolf']['abilities'][0]['playable'], 'pack tactics is advertised as active');
            expect_same(true, $roster['Pyromancer']['abilities'][0]['playable'], 'pyromancer fireball is advertised as playable');
            $flight = array_column($roster['Void Dragon']['abilities'], null, 'name')['Flying']['description'];
            expect_true(str_contains($flight, 'cannot land') && str_contains($flight, 'Poison pits still apply'), 'the setup card explains shared flying limits instead of promising poison immunity');
            $spawn = $started['setup']['spawnZoneHexes'][0];
            $placed = vanilla_submit($match, 'host', 'placeMonster', ['monster' => 'Marsh Hydra', 'hex' => $spawn]);
            $hydra = $placed['units'][0];
            expect_same(['Multiple Heads', 'Poisonous Bite', 'Outrage'], array_column($hydra['abilities'], 'name'), 'the supported essences appear on the active card');
            expect_same(true, $hydra['abilities'][0]['playable'], 'the essence is marked playable');
            expect_same([], $hydra['inactiveAbilities'], 'the marsh hydra has no deferred abilities left');
            $dragonPlaced = vanilla_submit($match, 'host', 'placeMonster', ['monster' => 'Void Dragon', 'hex' => $started['setup']['spawnZoneHexes'][1]]);
            $dragon = array_column($dragonPlaced['units'], null, 'unitId')['v-m2'];
            expect_same($flight, $dragon['abilities'][0]['description'], 'the placed dragon card explains the same flying rules');
        },
    ];
}
