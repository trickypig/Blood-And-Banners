<?php
declare(strict_types=1);

use BloodAndBanners\Ai\CommandCandidates;
use BloodAndBanners\Ai\Personalities;
use BloodAndBanners\Ai\SeededRandom;
use BloodAndBanners\Ai\TurnPlanner;
use BloodAndBanners\Rules\HexLineOfSight;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Sides;

/**
 * Per-step search budget in the soak loops, in ticks of a counting clock (one tick per deadline check, so
 * roughly one scored candidate each). Counting instead of wall time keeps every soak battle identical on
 * any machine, and the tight budget keeps whole battles quick to run.
 */
const AI_SOAK_STEP_TICKS = 40;
/** The hero bot is only the opposition here, so it searches less than the Void Lord (it is half the planning time). */
const AI_SOAK_HERO_STEP_TICKS = 30;
/** Hard cap on commands per battle, in case something loops without ending turns. */
const AI_SOAK_STEP_CAP = 20000;

/**
 * Plays one whole co-op battle on the real catalog: the personality plans and confirms its horde, the
 * draft takes the first legal pick, then every owed command comes from a fresh TurnPlanner (heroes are
 * the ravager in 'bot' mode, or end their turns at once in 'passive' mode).
 * @return array{result:?array, rounds:int, illegal:int, aiAttacks:int, firstContactRound:?int, attacksByRound:array<int,int>, idleInReach:int, shuffles:int, steps:int, ms:int}
 */
function ai_soak(string $scenario, string $personality, int $heroes, string $heroMode, int $roundCap, int $seed = 1): array
{
    static $cache = [];
    $key = "{$scenario}:{$personality}:{$heroes}:{$heroMode}:{$roundCap}:{$seed}";
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $startedMs = (int)floor(microtime(true) * 1000);
    $engine = new MatchEngine(ai_catalog());
    $state = ai_prep_state($scenario, $heroes);
    $state = ai_apply_plan($state, ai_plan($state, $personality, $seed));
    $state = ai_draft_to_battle($state);
    $void = (string)$state['voidLordSeatId'];
    $ravager = Personalities::get('ravager');
    $own = Personalities::get($personality);

    $illegal = 0;
    $aiAttacks = 0;
    $attacksByRound = [];
    $firstContact = null;
    $idleInReach = 0;
    $shuffles = 0;
    $open = [];
    $previous = [];
    $planMs = ['void' => 0.0, 'heroes' => 0.0];
    $steps = 0;
    while ($state['phase'] === 'active' && (int)$state['turn']['round'] <= $roundCap && $steps < AI_SOAK_STEP_CAP) {
        $steps++;
        $choice = $state['pending']['choice'] ?? null;
        $seat = $choice !== null ? (string)$choice['seatId'] : (string)$state['turn']['activeSeatId'];
        $round = (int)$state['turn']['round'];
        $stepSeed = $seed * 100_000 + $steps;
        $command = null;
        if ($seat !== $void && $heroMode === 'passive') {
            $commands = CommandCandidates::for($state, $seat, $engine->legalActions($state, $seat, AI_PLANNER_NOW));
            foreach ($commands as $candidate) {
                if ($candidate['kind'] === ($choice !== null ? 'resolveChoice' : 'endTurn')) {
                    $command = $candidate;
                    break;
                }
            }
        }
        if ($command === null) {
            $ticks = 0;
            $clock = static function () use (&$ticks): int {
                return $ticks++;
            };
            $planner = new TurnPlanner($engine, $seat === $void ? $own : $ravager, AI_PLANNER_NOW, $seat === $void ? AI_SOAK_STEP_TICKS : AI_SOAK_HERO_STEP_TICKS, $clock);
            $planStart = microtime(true);
            $command = $planner->next($state, $seat, $stepSeed);
            $planMs[$seat === $void ? 'void' : 'heroes'] += (microtime(true) - $planStart) * 1000;
        }
        if ($command === null) {
            throw new RuntimeException("{$seat} owes a command but the planner returned none (round {$round})");
        }
        $before = $state;
        try {
            $state = $engine->apply($state, $seat, $command['kind'], $command['payload'], new SeededRandom($stepSeed), AI_PLANNER_NOW)['state'];
        } catch (Throwable $error) {
            $illegal++;
            // Keep the battle going: pass the turn (or the first legal option) instead.
            $fallback = CommandCandidates::for($state, $seat, $engine->legalActions($state, $seat, AI_PLANNER_NOW));
            $pass = array_values(array_filter($fallback, static fn (array $c): bool => in_array($c['kind'], ['resolveChoice', 'endTurn'], true)))[0] ?? $fallback[0];
            $state = $engine->apply($state, $seat, $pass['kind'], $pass['payload'], new SeededRandom($stepSeed), AI_PLANNER_NOW)['state'];
            continue;
        }
        if ($seat !== $void) {
            continue;
        }
        $unitId = isset($command['payload']['unitId']) ? (string)$command['payload']['unitId'] : null;
        if ($unitId !== null && !isset($open[$unitId]) && isset($before['units'][$unitId])) {
            $open[$unitId] = ['start' => (string)$before['units'][$unitId]['hex'], 'attacked' => false];
        }
        if (in_array($command['kind'], ['attackUnit', 'specialAttack'], true)) {
            $aiAttacks++;
            $attacksByRound[$round] = ($attacksByRound[$round] ?? 0) + 1;
            $firstContact ??= $round;
            $open[$unitId]['attacked'] = true;
        }
        // A unit that closes its activation with an enemy in reach and no swing taken wastes the activation.
        if ($command['kind'] === 'endActivation' && !$open[$unitId]['attacked'] && ai_soak_could_strike($engine, $before, $void, $unitId)) {
            $idleInReach++;
            if (getenv('AI_SOAK_LOG')) {
                fwrite(STDERR, "  idle in reach: {$unitId} round {$round} at {$state['units'][$unitId]['hex']}" . PHP_EOL);
            }
        }
        foreach ($open as $id => $activation) {
            if (($state['turn']['activeUnitId'] ?? null) === $id && $state['turn']['activeSeatId'] === $void) {
                continue;
            }
            // Closed. Two attack-free activations in a row that undo each other are a purposeless shuffle.
            $end = (string)($state['units'][$id]['hex'] ?? '');
            $last = $previous[$id] ?? null;
            if (!$activation['attacked'] && $last !== null && !$last['attacked'] && $activation['start'] !== $end
                && $last['start'] === $end && $last['end'] === $activation['start']) {
                $shuffles++;
                if (getenv('AI_SOAK_LOG')) {
                    fwrite(STDERR, "  back again: {$id} round {$round} {$activation['start']} -> {$end}" . PHP_EOL);
                }
            }
            $previous[$id] = $activation + ['end' => $end];
            unset($open[$id]);
        }
    }
    $result = [
        'result' => $state['result'] ?? null,
        'rounds' => (int)$state['turn']['round'],
        'illegal' => $illegal,
        'aiAttacks' => $aiAttacks,
        'firstContactRound' => $firstContact,
        'attacksByRound' => $attacksByRound,
        'idleInReach' => $idleInReach,
        'shuffles' => $shuffles,
        'steps' => $steps,
        'ms' => (int)floor(microtime(true) * 1000) - $startedMs,
    ];
    if (getenv('AI_SOAK_LOG')) {
        fwrite(STDERR, sprintf(
            "soak %s %s %d %s: %s in %d rounds, %d steps, %d illegal, %d attacks, contact r%s, idle-in-reach %d, shuffles %d, %d ms (planning: void %d, heroes %d)\n",
            $scenario, $personality, $heroes, $heroMode, $result['result']['winningSide'] ?? 'cap', $result['rounds'], $steps, $illegal,
            $aiAttacks, $firstContact ?? '-', $idleInReach, $shuffles, $result['ms'], $planMs['void'], $planMs['heroes']
        ));
    }
    return $cache[$key] = $result;
}

function ai_soak_nearest_enemy(array $state, string $unitId, string $hex): int
{
    $nearest = PHP_INT_MAX;
    foreach ($state['units'] as $id => $unit) {
        if ($unit['hex'] !== null && empty($unit['fixed']) && Sides::ofUnit($state, (string)$id) !== Sides::ofUnit($state, $unitId)) {
            $nearest = min($nearest, Hex::distance($hex, (string)$unit['hex']));
        }
    }
    return $nearest;
}

/** Whether the unit has an attack or special attack aimed at an enemy-held hex right now. */
function ai_soak_could_strike(MatchEngine $engine, array $state, string $seatId, string $unitId): bool
{
    $enemyHexes = [];
    foreach ($state['units'] as $id => $unit) {
        if ($unit['hex'] !== null && Sides::ofUnit($state, (string)$id) !== Sides::ofUnit($state, $unitId)) {
            $enemyHexes[(string)$unit['hex']] = true;
        }
    }
    foreach ($engine->legalActions($state, $seatId, AI_PLANNER_NOW) as $action) {
        if (($action['unitId'] ?? null) === $unitId && in_array($action['kind'], ['attackUnit', 'specialAttack'], true)) {
            foreach ($action['targetHexes'] ?? [] as $hex) {
                if (isset($enemyHexes[(string)$hex])) {
                    return true;
                }
            }
        }
    }
    return false;
}

/** After first contact, every round up to the last one played in full has at least one AI attack. */
function ai_soak_pressure_gaps(array $soak): array
{
    if ($soak['firstContactRound'] === null) {
        return [];
    }
    $last = $soak['rounds'] - 1;
    $gaps = [];
    for ($round = $soak['firstContactRound']; $round <= $last; $round++) {
        if (($soak['attacksByRound'][$round] ?? 0) < 1) {
            $gaps[] = $round;
        }
    }
    return $gaps;
}

/** A random board with holes, sight blockers, occupants and elevation 0–4. */
function ai_soak_sight_board(SeededRandom $random): array
{
    $board = [];
    for ($q = 0; $q < 10; $q++) {
        for ($r = 0; $r < 10; $r++) {
            if ($random->roll(100) <= 8) {
                continue;
            }
            $board["{$q},{$r}"] = [
                'elevation' => $random->roll(100) <= 70 ? 0 : $random->roll(5) - 1,
                'sightBlocker' => $random->roll(100) <= 12,
                'occupantId' => $random->roll(100) <= 10 ? 'u' : null,
            ];
        }
    }
    return $board;
}

/** The pre-Task-4 line of sight: some shortest ray whose interior hexes are all clear. */
function ai_soak_rays_visible(HexLineOfSight $sight, string $from, string $to, array $board): bool
{
    if ($from === $to) {
        return true;
    }
    $height = max((int)$board[$from]['elevation'], (int)$board[$to]['elevation']);
    foreach ($sight->shortestRays($from, $to, $board) as $ray) {
        $clear = true;
        foreach (array_slice($ray, 1, -1) as $key) {
            $hex = $board[$key];
            if ($hex['occupantId'] !== null || $hex['sightBlocker'] || (int)$hex['elevation'] >= $height + 2) {
                $clear = false;
                break;
            }
        }
        if ($clear) {
            return true;
        }
    }
    return false;
}

function ai_soak_tests(): array
{
    return [
        'line of sight by layers matches the shortest-ray enumeration' => static function (): void {
            $sight = new HexLineOfSight();
            $random = new SeededRandom(29);
            $checked = 0;
            $mismatches = [];
            for ($b = 0; $b < 6; $b++) {
                $board = ai_soak_sight_board($random);
                $keys = array_keys($board);
                for ($i = 0; $i < 80; $i++) {
                    $from = (string)$keys[$random->roll(count($keys)) - 1];
                    $to = (string)$keys[$random->roll(count($keys)) - 1];
                    if (Hex::distance($from, $to) > 8) {
                        continue;
                    }
                    $checked++;
                    if ($sight->isVisible($from, $to, $board) !== ai_soak_rays_visible($sight, $from, $to, $board)) {
                        $mismatches[] = "{$b}:{$from}->{$to}";
                    }
                }
            }
            expect_true($checked >= 300, "enough pairs checked ({$checked})");
            expect_same([], $mismatches, 'no mismatches');
        },
        'every personality beats passive heroes without stalling' => static function (): void {
            foreach (array_keys(Personalities::all()) as $id) {
                $soak = ai_soak('void-reaches', $id, 2, 'passive', 40);
                expect_same(0, $soak['illegal'], "{$id} plays legally");
                expect_same(0, $soak['idleInReach'], "{$id} never ends an activation with an enemy in reach unstruck");
                expect_true($soak['shuffles'] <= 2, "{$id} rarely walks a unit back and forth ({$soak['shuffles']})");
                expect_same('void', $soak['result']['winningSide'] ?? null, "{$id} wins (after {$soak['rounds']} rounds)");
            }
        },
        'bot battles finish legally' => static function (): void {
            foreach (['void-reaches', 'incursion-lakeheart'] as $scenario) {
                foreach (array_keys(Personalities::all()) as $id) {
                    $soak = ai_soak($scenario, $id, 1, 'bot', 60);
                    expect_same(0, $soak['illegal'], "{$id} on {$scenario} plays legally");
                    expect_same(0, $soak['idleInReach'], "{$id} on {$scenario} never ends an activation with an enemy in reach unstruck");
                    expect_true($soak['shuffles'] <= 2, "{$id} on {$scenario} rarely walks a unit back and forth ({$soak['shuffles']})");
                    if ($soak['result'] === null) {
                        expect_same([], ai_soak_pressure_gaps($soak), "{$id} on {$scenario} attacks every round after first contact");
                    }
                }
            }
        },
        'personalities feel different on the board' => static function (): void {
            // Same map, seed and (passive) heroes: the ravager rushes in, the warden holds its post first.
            $ravager = ai_soak('void-reaches', 'ravager', 2, 'passive', 40);
            $warden = ai_soak('void-reaches', 'warden', 2, 'passive', 40);
            expect_true($ravager['firstContactRound'] !== null && $warden['firstContactRound'] !== null, 'both make contact');
            expect_true(
                $ravager['firstContactRound'] < $warden['firstContactRound'],
                "the ravager strikes first (round {$ravager['firstContactRound']} vs {$warden['firstContactRound']})"
            );
        },
        'a planner step stays inside its budget' => static function (): void {
            $engine = new MatchEngine(ai_catalog());
            $state = ai_prep_state('void-reaches', 4);
            $state = ai_apply_plan($state, ai_plan($state, 'hollowKing'));
            $state = ai_draft_to_battle($state);
            $void = (string)$state['voidLordSeatId'];
            $slowest = 0;
            $steps = 0;
            // Hero seats pass until the Void Lord is up, then time its first few full-budget steps.
            for ($i = 0; $i < 60 && $steps < 6 && $state['phase'] === 'active'; $i++) {
                $choice = $state['pending']['choice'] ?? null;
                $seat = $choice !== null ? (string)$choice['seatId'] : (string)$state['turn']['activeSeatId'];
                if ($seat !== $void) {
                    $commands = CommandCandidates::for($state, $seat, $engine->legalActions($state, $seat, AI_PLANNER_NOW));
                    $command = array_values(array_filter($commands, static fn (array $c): bool => in_array($c['kind'], ['resolveChoice', 'endTurn'], true)))[0];
                } else {
                    $start = microtime(true);
                    $command = ai_planner('hollowKing', $engine)->next($state, $void, $i + 1);
                    $slowest = max($slowest, (int)round((microtime(true) - $start) * 1000));
                    $steps++;
                }
                $state = $engine->apply($state, $seat, $command['kind'], $command['payload'], new SeededRandom($i + 1), AI_PLANNER_NOW)['state'];
            }
            fwrite(STDOUT, "  slowest of {$steps} full-budget steps on a " . count($state['units']) . "-unit board: {$slowest} ms\n");
            expect_true($steps > 0, 'the Void Lord was timed');
            expect_true($slowest < 3000, "slowest step {$slowest} ms");
        },
    ];
}
