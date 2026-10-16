<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\BattleRules;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Turn;
use BloodAndBanners\Rules\Vanilla\UnitConditions;
use BloodAndBanners\Rules\Vanilla\Hex;

function control_choice_cast(array $state, string $unit, string $ability, array $targets, array $rolls = []): array
{
    return coop_command($state, $state['units'][$unit]['seatId'], 'specialAttack', ['unitId' => $unit, 'ability' => $ability, 'targetUnitIds' => $targets], $rolls);
}

function toss_battle(array $target = []): array
{
    return coop_battle([
        monster_unit('dragon', 'Void Dragon', '0,0'), monster_unit('idle', 'Ice Wolf', '8,0'),
        coop_hero('target', 'hero-a', '1,0', $target + ['hp' => 100, 'defense' => 0]),
    ], 'void-lord');
}

function pipes_battle(): array
{
    return coop_battle([
        monster_unit('piper', 'Satyr Archer', '4,0', ['hp' => 100, 'defense' => 0]),
        monster_unit('satyr', 'Satyr Warrior', '3,0', ['hp' => 100, 'defense' => 0]),
        monster_unit('idle', 'Ice Wolf', '8,0'),
        coop_hero('attacker', 'hero-a', '2,0', ['hp' => 100, 'power' => 2, 'range' => 1]),
        coop_hero('friend', 'hero-a', '1,0', ['hp' => 100, 'defense' => 0]),
    ], 'void-lord');
}

function pipes_ready(?array $state = null): array
{
    $state = control_choice_cast($state ?? pipes_battle(), 'piper', 'Pan Pipes', ['piper'])['state'];
    return coop_command($state, 'void-lord', 'endTurn')['state'];
}

function pipes_attack(array $state, array $rolls): array
{
    return coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'attacker', 'targetUnitId' => 'satyr'], $rolls);
}

function control_choice_resolve(array $state, string $seat, string $option, array $rolls = []): array
{
    return coop_command($state, $seat, 'resolveChoice', ['choiceId' => $state['pending']['choice']['choiceId'], 'option' => $option], $rolls);
}

function control_choice_service(array $state): array
{
    $match = coop_service_match();
    $match['games'] = new \BloodAndBanners\Game\GameService($match['db'], null, new ScriptedRandom(), new MatchEngine(new \BloodAndBanners\Rules\Vanilla\Catalog()));
    vanilla_submit($match, 'host', 'claimVoidLord', []);
    coop_ready_all($match);
    $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
    $void = $match['host']['seat'];
    $hero = $match['hero1']['seat'];
    $seats = ['void-lord' => $void, 'hero-a' => $hero];
    $active = $seats[$state['turn']['activeSeatId']];
    $state['seats'] = [$hero, $void];
    $state['voidLordSeatId'] = $void;
    $state['initiative'] = ['rolls' => [$hero => [20]], 'order' => [$hero]];
    $state['sides'] = ['heroes' => ['kind' => 'heroes', 'seatIds' => [$hero]], 'void' => ['kind' => 'void', 'seatIds' => [$void]]];
    foreach ($state['units'] as &$unit) {
        $unit['seatId'] = $seats[$unit['seatId']];
    }
    unset($unit);
    foreach ($state['conditions'] as &$condition) {
        if (isset($condition['expires']['seatId'])) {
            $condition['expires']['seatId'] = $seats[$condition['expires']['seatId']];
        }
    }
    unset($condition);
    $state['turn'] = Turn::fresh($state, $active, 1, 1);
    $match['db']->prepare('UPDATE game_states SET state_json = :state WHERE game_id = :game')->execute(['state' => json_encode($state, JSON_THROW_ON_ERROR), 'game' => $match['gameId']]);
    $match['db']->prepare("UPDATE games SET lifecycle = 'active' WHERE id = :game")->execute(['game' => $match['gameId']]);
    return $match;
}

function control_choice_attack_tests(): array
{
    return [
        'sky toss saves on fifteen and a failed save gives only the dragon owner legal landing choices' => static function (): void {
            foreach ([15, 20] as $save) {
                $result = control_choice_cast(toss_battle(), 'dragon', 'Sky Toss', ['target'], [$save]);
                expect_same(null, $result['state']['pending'], 'a successful save prevents the toss');
                expect_same('1,0', $result['state']['units']['target']['hex'], 'the saved target stays put');
            }
            $state = control_choice_cast(toss_battle(), 'dragon', 'Sky Toss', ['target'], [14])['state'];
            expect_same('skyToss', $state['pending']['choice']['kind'], 'a failed save opens the landing decision');
            expect_same('void-lord', $state['pending']['choice']['seatId'], 'the dragon owner chooses');
            expect_true(in_array('land:5,0', $state['pending']['choice']['options'], true), 'a landing four hexes from the victim is allowed');
            foreach (['land:0,0', 'land:1,0', 'land:6,0', 'land:8,0'] as $option) {
                expect_true(!in_array($option, $state['pending']['choice']['options'], true), 'occupied and distant destinations are excluded');
            }
            expect_same([], (new BattleRules(PHP_INT_MAX))->legalActions($state, 'hero-a'), 'the opponent cannot take over this choice after a timeout');
            expect_game_error('notYourChoice', static fn() => control_choice_resolve($state, 'hero-a', 'land:5,0'));
            expect_game_error('unknownChoice', static fn() => control_choice_resolve($state, 'void-lord', 'land:6,0'));
        },
        'sky toss uses the saved landing choice and exactly three undefended fall dice despite height and obstacles' => static function (): void {
            $state = toss_battle(['defense' => 99]);
            $state['board']['3,0']['sightBlocker'] = true;
            $state['board']['5,0']['elevation'] = 8;
            $state = control_choice_cast($state, 'dragon', 'Sky Toss', ['target'], [1])['state'];
            $state = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $result = control_choice_resolve($state, 'void-lord', 'land:5,0', [1, 1, 1]);
            expect_same('5,0', $result['state']['units']['target']['hex'], 'the unit flies over intervening walls');
            expect_same(3, $result['state']['units']['target']['wounds'], 'fall damage is always three dice without defense');
            expect_same(null, $result['state']['pending'], 'the saved choice finishes');
            expect_game_error('unitAlreadyActivated', static fn() => control_choice_cast($result['state'], 'dragon', 'Dragon Breath', ['target']));
        },
        'sky toss applies landing terrain and never grants a second evasion after the save' => static function (): void {
            foreach (['poison-pits', 'lava', 'ice'] as $terrain) {
                $state = toss_battle();
                $state['board']['5,0']['terrain'] = $terrain;
                $state = control_choice_cast($state, 'dragon', 'Sky Toss', ['target'], [14])['state'];
                $result = control_choice_resolve($state, 'void-lord', 'land:5,0', array_fill(0, $terrain === 'lava' ? 8 : 3, 1));
                expect_same($terrain === 'lava' ? 8 : 3, $result['state']['units']['target']['wounds'], 'lava entry adds its five dice to the three fall dice');
                expect_same($terrain === 'poison-pits', UnitConditions::has($result['state'], 'target', 'Poisoned'), 'poison landing applies poison');
                expect_same($terrain === 'ice', UnitConditions::has($result['state'], 'target', 'Unsteady'), 'ice landing is unsteady');
            }
            $state = toss_battle(['abilities' => [['name' => 'Evasion']]]);
            $evaded = control_choice_cast($state, 'dragon', 'Sky Toss', ['target'], [11]);
            expect_same(null, $evaded['state']['pending'], 'an evaded toss needs no save or landing');
            $failed = control_choice_cast($state, 'dragon', 'Sky Toss', ['target'], [10, 14]);
            $landed = control_choice_resolve($failed['state'], 'void-lord', 'land:5,0', [1, 1, 1]);
            expect_same(3, $landed['state']['units']['target']['wounds'], 'the saved choice does not reroll evasion');
        },
        'sky toss rejects distant allied and extra targets without rolling' => static function (): void {
            $state = toss_battle();
            $state['units']['target']['hex'] = '2,0';
            expect_game_error('invalidAbilityTarget', static fn() => control_choice_cast($state, 'dragon', 'Sky Toss', ['target']));
            foreach ([['idle'], [], ['target', 'idle']] as $targets) {
                expect_game_error('invalidAbilityTarget', static fn() => control_choice_cast(toss_battle(), 'dragon', 'Sky Toss', $targets));
            }
        },
        'pan pipes spends the attack to protect nearby satyrs until the next owning turn' => static function (): void {
            $state = control_choice_cast(pipes_battle(), 'piper', 'Pan Pipes', ['piper'])['state'];
            expect_true(UnitConditions::has($state, 'piper', 'Pan Pipes'), 'the piper projects its active protection');
            expect_game_error('unitAlreadyActivated', static fn() => coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'piper', 'targetUnitId' => 'attacker']));
            $state = coop_command($state, 'void-lord', 'endTurn')['state'];
            expect_true(UnitConditions::has($state, 'piper', 'Pan Pipes'), 'protection lasts through the enemy turn');
            $state = coop_command($state, 'hero-a', 'endTurn')['state'];
            expect_true(!UnitConditions::has($state, 'piper', 'Pan Pipes'), 'protection ends at the start of its owner next turn');
        },
        'pan pipes saves on fifteen and otherwise lets the satyr owner redirect the attack to an ally' => static function (): void {
            $saved = pipes_attack(pipes_ready(), [15, 1, 1]);
            expect_same(2, $saved['state']['units']['satyr']['wounds'], 'saving permits the original attack');
            $state = pipes_attack(pipes_ready(), [14])['state'];
            expect_same('panPipes', $state['pending']['choice']['kind'], 'failure opens a redirect decision');
            expect_same('void-lord', $state['pending']['choice']['seatId'], 'the satyr owner controls the target');
            expect_same(['target:friend'], $state['pending']['choice']['options'], 'only a visible in-range ally is offered, never the attacker itself');
            expect_game_error('notYourChoice', static fn() => control_choice_resolve($state, 'hero-a', 'target:friend'));
            $state = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $result = control_choice_resolve($state, 'void-lord', 'target:friend', [1, 1]);
            expect_same([0, 2], [$result['state']['units']['satyr']['wounds'], $result['state']['units']['friend']['wounds']], 'the redirected ally takes the original attack');
            expect_game_error('unitAlreadyActivated', static fn() => pipes_attack($result['state'], []));
        },
        'pan pipes wastes the attack if no legal allied target exists' => static function (): void {
            $state = pipes_battle();
            $state['units']['friend']['hex'] = '0,0';
            $result = pipes_attack(pipes_ready($state), [14]);
            expect_same(null, $result['state']['pending'], 'there is no impossible choice');
            expect_same(0, $result['state']['units']['satyr']['wounds'], 'the satyr remains protected');
            expect_game_error('unitAlreadyActivated', static fn() => pipes_attack($result['state'], []));
        },
        'pan pipes follows the actual special attack range and moves its splash center to the chosen ally' => static function (): void {
            $state = pipes_battle();
            $state['units']['attacker']['hex'] = '0,0';
            $state['units']['attacker']['abilities'] = [['name' => 'Fireball', 'type' => 'Attack', 'description' => 'Power 4, Range 4']];
            $state['board'] += vanilla_board(['0,1' => 0, '0,2' => 0, '0,3' => 0]);
            $state['units']['friend']['hex'] = '0,3';
            $state = pipes_ready($state);
            $state = control_choice_cast($state, 'attacker', 'Fireball', ['satyr'], [14])['state'];
            expect_same(['target:friend'], $state['pending']['choice']['options'], 'the spell can be redirected beyond the attacker normal range one');
            $result = control_choice_resolve($state, 'void-lord', 'target:friend', [1, 1, 1, 1]);
            expect_same([4, 0, 0], [$result['state']['units']['friend']['wounds'], $result['state']['units']['satyr']['wounds'], $result['state']['units']['piper']['wounds']], 'the spell resolves at its new target rather than splashing its old center');
        },
        'pan pipes redirects control attacks and gives the replacement target its own save' => static function (): void {
            $state = pipes_battle();
            $state['units']['attacker']['abilities'] = [['name' => 'Petrifying Gaze']];
            $state = pipes_ready($state);
            $state = control_choice_cast($state, 'attacker', 'Petrifying Gaze', ['satyr'], [14])['state'];
            $result = control_choice_resolve($state, 'void-lord', 'target:friend', [12]);
            expect_true(UnitConditions::has($result['state'], 'friend', 'Petrified'), 'the replacement target fails its own gaze save');
            expect_true(!UnitConditions::has($result['state'], 'satyr', 'Petrified'), 'the intended satyr is unharmed');
        },
        'pan pipes does not protect distant satyrs and evasion belongs to the replacement target' => static function (): void {
            $state = pipes_battle();
            $state['units']['piper']['hex'] = '7,0';
            $result = pipes_attack(pipes_ready($state), [1, 1]);
            expect_same(2, $result['state']['units']['satyr']['wounds'], 'a satyr outside the three-hex aura is attacked normally');
            $state = pipes_battle();
            $state['units']['friend']['abilities'] = [['name' => 'Evasion']];
            $state = pipes_attack(pipes_ready($state), [14])['state'];
            $result = control_choice_resolve($state, 'void-lord', 'target:friend', [11]);
            expect_same([0, 0], [$result['state']['units']['friend']['wounds'], $result['state']['units']['satyr']['wounds']], 'the chosen ally may evade the redirected attack');
        },
        'pan pipes can redirect a column toss after its one-use cost has already been paid' => static function (): void {
            $state = pipes_battle();
            $state['units']['attacker']['hex'] = '0,0';
            $state['units']['attacker']['abilities'] = [['name' => 'Column Toss']];
            $state['board'] += vanilla_board(['0,1' => 0, '0,2' => 0, '0,3' => 0]);
            $state['units']['friend']['hex'] = '0,3';
            $state = pipes_ready($state);
            $state = control_choice_cast($state, 'attacker', 'Column Toss', ['satyr'], [14])['state'];
            expect_same(['target:friend'], $state['pending']['choice']['options'] ?? [], 'paying a cost does not make the already-started attack impossible to redirect');
            $result = control_choice_resolve($state, 'void-lord', 'target:friend', [1, 1, 1, 1, 1, 1, 1]);
            expect_same(7, $result['state']['units']['friend']['wounds'], 'the original special power is retained');
            expect_same(true, $result['state']['units']['attacker']['flags']['columnTossed'], 'redirection never refunds the column');
        },
        'a stone grip redirected onto the attacker own side lasts through the victim next turn' => static function (): void {
            $state = pipes_battle();
            $state['units']['reserve'] = coop_hero('reserve', 'hero-a', '0,0', ['hp' => 100]);
            $state['units']['attacker']['abilities'] = [['name' => 'Stone Grip']];
            $state = pipes_ready($state);
            $state = control_choice_cast($state, 'attacker', 'Stone Grip', ['satyr'], [14])['state'];
            $state = control_choice_resolve($state, 'void-lord', 'target:friend', [11, 6, 6])['state'];
            expect_true(UnitConditions::has($state, 'friend', 'Entrapped'), 'the redirected grip traps an allied victim');
            $state = coop_command($state, 'hero-a', 'endTurn')['state'];
            expect_true(UnitConditions::has($state, 'friend', 'Entrapped'), 'ending the current victim turn must not clear next-turn entrapment');
            $state = coop_command($state, 'void-lord', 'endTurn')['state'];
            expect_true(UnitConditions::has($state, 'friend', 'Entrapped'), 'the victim remains entrapped during its next turn');
            $state = coop_command($state, 'hero-a', 'endTurn')['state'];
            expect_true(!UnitConditions::has($state, 'friend', 'Entrapped'), 'the condition clears at the end of that next turn');
        },
        'public control choices survive reconnects and retries without leaking the decision to the opponent' => static function (): void {
            foreach (['panPipes', 'skyToss'] as $kind) {
                $match = control_choice_service($kind === 'panPipes' ? pipes_ready() : toss_battle());
                $catalog = new \BloodAndBanners\Rules\Vanilla\Catalog();
                $match['games'] = new \BloodAndBanners\Game\GameService($match['db'], null, new SequenceRandom([14]), new MatchEngine($catalog));
                $command = $kind === 'panPipes' ? 'attackUnit' : 'specialAttack';
                $actor = $kind === 'panPipes' ? 'hero1' : 'host';
                $payload = $kind === 'panPipes'
                    ? ['unitId' => 'attacker', 'targetUnitId' => 'satyr']
                    : ['unitId' => 'dragon', 'ability' => 'Sky Toss', 'targetUnitIds' => ['target']];
                $opened = vanilla_submit($match, $actor, $command, $payload, 'open-control');
                $retry = vanilla_submit($match, $actor, $command, $payload, 'open-control');
                expect_same($opened['version'], $retry['version'], 'a command retry never rolls the control save twice');
                $owner = $match['games']->getGame($match['gameId'], $match['host']['identity']);
                expect_same($kind, $owner['pendingChoice']['kind'], 'the correct choice is projected to its owner');
                expect_same(null, $owner['pendingChoice']['expiresAt'], 'control choices have no keep-roll timeout');
                $opponent = $match['games']->getGame($match['gameId'], $match['hero1']['identity']);
                expect_same(null, $opponent['pendingChoice'], 'the opposing player only receives a waiting summary');
                expect_same($kind, $opponent['pendingChoiceSummary']['kind'], 'the waiting summary names the control effect');
                $match['games'] = new \BloodAndBanners\Game\GameService($match['db'], null, new SequenceRandom($kind === 'panPipes' ? [1, 1] : [1, 1, 1]), new MatchEngine($catalog));
                $choice = ['choiceId' => $owner['pendingChoice']['choiceId'], 'option' => $kind === 'panPipes' ? 'target:friend' : 'land:5,0'];
                $resolved = vanilla_submit($match, 'host', 'resolveChoice', $choice, 'resolve-control');
                $retry = vanilla_submit($match, 'host', 'resolveChoice', $choice, 'resolve-control');
                expect_same($resolved['version'], $retry['version'], 'a choice retry never repeats damage');
                $loaded = $match['games']->getGame($match['gameId'], $match['host']['identity']);
                $units = array_column($loaded['units'], null, 'unitId');
                expect_same($kind === 'panPipes' ? 2 : 3, $units[$kind === 'panPipes' ? 'friend' : 'target']['wounds'], 'the saved choice resolves exactly once');
                expect_same(null, $loaded['pendingChoiceSummary'], 'no waiting state remains after resolution');
            }
        },
        'pan pipes protects the piper itself and moving the aura source changes who is protected' => static function (): void {
            $state = pipes_battle();
            $state['units']['satyr']['hex'] = '6,0';
            $state['units']['attacker']['hex'] = '3,0';
            $state['units']['friend']['hex'] = '2,0';
            $state = pipes_ready($state);
            $opened = coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'attacker', 'targetUnitId' => 'piper'], [14]);
            expect_same('panPipes', $opened['state']['pending']['choice']['kind'] ?? null, 'the piper protects itself');
            $state = pipes_ready();
            $events = [];
            $state = \BloodAndBanners\Rules\Vanilla\Forced::push($state, 'piper', [1, 0], 3, 'attacker', new SequenceRandom([]), $events);
            expect_true(UnitConditions::has($state, 'piper', 'Pan Pipes'), 'forced movement preserves the active aura');
            $result = pipes_attack($state, [1, 1]);
            expect_same(2, $result['state']['units']['satyr']['wounds'], 'the aura follows the piper new position rather than its cast hex');
        },
        'overlapping pan pipes auras require exactly one save for a targeted satyr' => static function (): void {
            $state = pipes_battle();
            $state['units']['second'] = monster_unit('second', 'Satyr Archer', '5,0', ['hp' => 100]);
            $state = control_choice_cast($state, 'piper', 'Pan Pipes', ['piper'])['state'];
            $state = control_choice_cast($state, 'second', 'Pan Pipes', ['second'])['state'];
            $state = coop_command($state, 'void-lord', 'endTurn')['state'];
            $result = pipes_attack($state, [14]);
            expect_same(1, count(array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'saveRolled')), 'overlapping auras do not stack saves');
            $result = control_choice_resolve($result['state'], 'void-lord', 'target:friend', [1, 1]);
            expect_same(2, $result['state']['units']['friend']['wounds'], 'choosing an ally does not trigger the other aura');
        },
        'pan pipes does not redirect automatic collateral but keeps selected secondary target shape' => static function (): void {
            $state = pipes_battle();
            $state['units']['attacker']['hex'] = '0,0';
            $state['units']['attacker']['abilities'] = [['name' => 'Fireball']];
            $state['units']['friend']['hex'] = '0,3';
            $state['units']['satyr']['hex'] = '4,0';
            $state['units']['piper']['hex'] = '5,0';
            $state['units']['dummy'] = monster_unit('dummy', 'Ice Wolf', '3,0', ['hp' => 100, 'defense' => 0]);
            $state['board'] += vanilla_board(['0,1' => 0, '0,2' => 0, '0,3' => 0]);
            $state = pipes_ready($state);
            $result = control_choice_cast($state, 'attacker', 'Fireball', ['dummy'], [1, 1, 1, 1, 1]);
            expect_same(1, $result['state']['units']['satyr']['wounds'], 'a protected satyr can still take automatically discovered splash');
            expect_same(0, count(array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'saveRolled')), 'only direct selections trigger a pipes save');
            $state = pipes_battle();
            $state['units']['attacker']['hex'] = '0,0';
            $state['units']['attacker']['name'] = 'Pyromancer';
            $state['units']['attacker']['power'] = 3;
            $state['units']['attacker']['range'] = 3;
            $state['units']['attacker']['abilities'] = [['name' => 'Fireball']];
            $state['units']['friend']['hex'] = '2,1';
            $state['units']['far'] = coop_hero('far', 'hero-a', '0,3', ['hp' => 100]);
            $state['units']['dummy'] = monster_unit('dummy', 'Ice Wolf', '2,0', ['hp' => 100, 'defense' => 0]);
            $state['board'] += vanilla_board(['2,1' => 0, '0,1' => 0, '0,2' => 0, '0,3' => 0]);
            $state = pipes_ready($state);
            $opened = control_choice_cast($state, 'attacker', 'Fireball', ['dummy', 'satyr'], [14])['state'];
            expect_same(['target:friend'], $opened['pending']['choice']['options'], 'only allies adjacent to the selected primary are valid secondary replacements');
            $result = control_choice_resolve($opened, 'void-lord', 'target:friend', [1, 1, 1, 1, 1]);
            expect_same([3, 2, 0], [$result['state']['units']['dummy']['wounds'], $result['state']['units']['friend']['wounds'], $result['state']['units']['satyr']['wounds']], 'redirection retains the primary and only replaces the selected secondary');
        },
    ];
}
