<?php
declare(strict_types=1);

namespace BloodAndBanners\Ai;

use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Sides;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\Turn;
use Closure;

/**
 * Chooses one command at a time for an AI seat: answer its pending choice, finish the active
 * unit's activation, or pick which unit to activate next. Every option is played forward through
 * the real engine (Simulator) and scored by the personality's Evaluator under the current Stance.
 */
final class TurnPlanner
{
    public const STEP_BUDGET_MS = 1500;

    /** A plan must beat doing nothing by more than this to be worth an activation. */
    private const EPSILON = 1e-6;
    /** Commands that can follow a move inside the same activation. */
    private const FOLLOW_KINDS = ['attackUnit', 'specialAttack', 'useAbility'];
    private const IMMEDIATE_KINDS = ['attackUnit', 'specialAttack', 'useAbility', 'useItem', 'pickUpItem'];
    private const MOVE_KINDS = ['moveUnit', 'moveAbility'];
    /** Follow-ups are not looked for when every enemy is farther than this (also keeps sight checks cheap). */
    private const FOLLOW_REACH = 10;
    /** Destinations per unit in the quick scan that ranks units before the full search. */
    private const SCAN_DESTINATIONS = 2;
    /** Units reranked by the two-unit lookahead. */
    private const PAIR_POOL = 3;
    /** Units given a last quick look when the clock runs out before any plan gains. */
    private const SALVAGE_UNITS = 3;
    /** How much nearer, per point of cover weight, a destination beside a sight blocker ranks in pruning. */
    private const COVER_HEXES = 2;

    private readonly Simulator $simulator;
    private readonly Evaluator $evaluator;
    private readonly Closure $clock;
    private bool $expired = false;
    private int $searchUntilMs = 0;
    private string $seatId = '';
    private string $side = '';
    private int $seed = 0;
    private array $post = [];
    private bool $holding = false;
    /** @var array<string,float> */
    private array $baseCache = [];

    public function __construct(
        private readonly MatchEngine $engine,
        private readonly Personality $personality,
        private readonly int $nowSeconds,
        private readonly int $deadlineMs,
        ?Closure $clockMs = null,
    ) {
        $this->simulator = new Simulator($engine, $nowSeconds);
        $this->evaluator = new Evaluator($personality, true);
        $this->clock = $clockMs ?? static fn (): int => (int)floor(microtime(true) * 1000);
    }

    /** @return array{kind:string, payload:array}|null the next command, or null when this seat owes nothing */
    public function next(array $state, string $seatId, int $seed): ?array
    {
        $state = MatchEngine::normalize($state);
        if ($state['phase'] !== 'active' || ($state['result'] ?? null) !== null) {
            return null;
        }
        $choice = $state['pending']['choice'] ?? null;
        if ($choice !== null && $choice['seatId'] !== $seatId) {
            return null;
        }
        if ($choice === null && $state['turn']['activeSeatId'] !== $seatId) {
            return null;
        }
        $legal = $this->engine->legalActions($state, $seatId, $this->nowSeconds);
        $commands = CommandCandidates::for($state, $seatId, $legal);
        if ($commands === []) {
            return null;
        }

        $this->expired = false;
        $startMs = ($this->clock)();
        $this->searchUntilMs = $this->deadlineMs - max(0, intdiv($this->deadlineMs - $startMs, 3));
        $this->seatId = $seatId;
        $this->side = Stance::sideOf($state, $seatId);
        $this->seed = $seed;
        $this->post = Stance::post($state);
        $this->holding = Stance::holding($state, $seatId, $this->personality);
        $this->baseCache = [];
        $dice = new SeededRandom($seed ^ 0x5A17);

        if ($choice !== null) {
            return $this->resolveChoice($state, $commands, $dice);
        }
        $active = $state['turn']['activeUnitId'];
        if ($active !== null && ($state['units'][$active]['seatId'] ?? null) === $seatId) {
            return $this->finishActivation($state, $legal, $commands, (string)$active, $dice);
        }
        return $this->chooseActivation($state, $legal, $commands, $dice);
    }

    /** Step 1: score each option of this seat's pending choice. */
    private function resolveChoice(array $state, array $commands, SeededRandom $dice): array
    {
        $choices = array_values(array_filter($commands, static fn (array $c): bool => $c['kind'] === 'resolveChoice'));
        $weights = Stance::weights($state, $this->seatId, $this->personality);
        $plans = [];
        foreach ($choices as $command) {
            if ($this->timeUp()) {
                break;
            }
            $score = $this->meanScore($this->outcomes($state, $command, $this->samples()), $weights);
            if ($score !== null) {
                $plans[] = ['score' => $score, 'command' => $command];
            }
        }
        $pick = $this->pick($plans, $dice);
        return $pick['command'] ?? $choices[0] ?? $commands[0];
    }

    /**
     * Step 2: the rest of the active unit's activation. When nothing beats standing still the activation is over,
     * but endActivation shows nothing on the board, so the step goes on to what comes next instead (see chainPast).
     */
    private function finishActivation(array $state, array $legal, array $commands, string $unitId, SeededRandom $dice): array
    {
        $weights = $this->weightsFor($state, $unitId);
        $plans = [];
        $this->planUnit($state, $legal, $commands, $unitId, $weights, $this->destinationLimit(), $plans);
        $pool = $this->hardRule($plans);
        $end = self::find($commands, 'endActivation');
        if ($pool === null) {
            $pool = $plans;
            if ($end !== null) {
                // Listed first, so a plan has to beat standing still to be chosen.
                array_unshift($pool, ['score' => $this->baseScore($state, $weights) + self::EPSILON, 'command' => $end]);
            }
        }
        $pick = $this->pick($pool, $dice);
        if ($pick !== null && $pick['command']['kind'] === 'endActivation') {
            return $this->chainPast($state, $commands, $pick['command'], $dice);
        }
        return $pick['command'] ?? $end ?? self::fallback($commands);
    }

    /**
     * The engine closes an activation by itself when another unit acts (or the turn ends), so instead of a silent
     * endActivation step this plays it on paper and returns the next visible command: another unit's move or
     * attack, or endTurn. When closing leaves nobody able to act (the engine would pass the turn) it is endTurn.
     * It keeps endActivation when that is safer: the search clock has run out (the next step searches afresh
     * rather than pass the turn untried), closing opens a choice, or the next command would not be legal while
     * this unit is still active.
     */
    private function chainPast(array $state, array $commands, array $end, SeededRandom $dice): array
    {
        if ($this->timeUp()) {
            return $end;
        }
        $closed = $this->outcomes($state, $end, 1)[0] ?? null;
        if ($closed !== null && $closed['phase'] === 'active' && $closed['pending'] === null && $closed['turn']['activeSeatId'] !== $this->seatId) {
            return self::find($commands, 'endTurn') ?? $end;
        }
        if ($closed === null || $closed['phase'] !== 'active' || $closed['pending'] !== null || $closed['turn']['activeUnitId'] !== null) {
            return $end;
        }
        $legal = $this->engine->legalActions($closed, $this->seatId, $this->nowSeconds);
        $commands = CommandCandidates::for($closed, $this->seatId, $legal);
        if ($commands === []) {
            return $end;
        }
        $next = $this->chooseActivation($closed, $legal, $commands, $dice);
        if ($next['kind'] === 'endActivation' || ($next['kind'] === 'endTurn' && $this->expired)) {
            return $end;
        }
        // Legal as things stand, with this unit still active? (Played on the real state, so the answer is the engine's.)
        return $this->outcomes($state, $next, 1) === [] ? $end : $next;
    }

    /**
     * Step 3: activate the unit whose best plan gains the most, or end the turn when none gains.
     * A quick scan (two destinations each) ranks the units; the most promising get the full search.
     */
    private function chooseActivation(array $state, array $legal, array $commands, SeededRandom $dice): array
    {
        $endTurn = self::find($commands, 'endTurn');
        $results = [];
        $unscanned = $this->rankUnits($state, $commands);
        while ($unscanned !== []) {
            if ($this->timeUp()) {
                break;
            }
            $unitId = array_shift($unscanned);
            $result = $this->bestPlanFor($state, $legal, $commands, $unitId, self::SCAN_DESTINATIONS);
            if ($result !== null) {
                $results[$unitId] = $result;
            }
        }
        $order = array_values($results);
        usort($order, static fn (array $a, array $b): int => $b['gain'] <=> $a['gain']);
        foreach (array_slice($order, 0, $this->refineCount()) as $scanned) {
            if ($this->timeUp()) {
                break;
            }
            $full = $this->bestPlanFor($state, $legal, $commands, $scanned['unitId'], $this->destinationLimit());
            if ($full !== null && $full['gain'] > $scanned['gain']) {
                $results[$scanned['unitId']] = $full;
            }
        }
        if ($this->personality->sharpness['pairs'] ?? false) {
            $results = $this->rerankByPairs($state, $results);
        }
        $ranked = array_values(array_filter($results, static fn (array $r): bool => $r['gain'] > self::EPSILON));
        usort($ranked, static fn (array $a, array $b): int => $b['gain'] <=> $a['gain']);
        if ($ranked === [] && $this->expired) {
            // The clock ran out before any unit showed a gain: never pass the turn on that alone.
            $salvaged = $this->salvage($state, $legal, $commands, $unscanned);
            if ($salvaged !== null) {
                return $salvaged;
            }
        }
        if ($ranked === []) {
            // A charmed unit that must walk first leaves no endTurn; then its best plan is the only way on.
            $first = $results === [] ? null : reset($results);
            return $endTurn ?? $first['plan']['command'] ?? self::fallback($commands);
        }
        $chosen = $ranked[0];
        if (count($ranked) > 1 && $this->blunders($dice)) {
            $chosen = $ranked[1];
        }
        return $chosen['plan']['command'];
    }

    /**
     * Past the deadline: give a few units the search never reached one quick look each (their most promising
     * safe destination, one sample, no follow-up) and return the first move that gains, else null. Bounded work.
     */
    private function salvage(array $state, array $legal, array $commands, array $unscanned): ?array
    {
        // The farthest unreached unit first (the near ones are the likeliest to be stuck), then the nearest.
        $look = $unscanned === [] ? [] : array_unique([end($unscanned), ...$unscanned]);
        foreach (array_slice($look, 0, self::SALVAGE_UNITS) as $unitId) {
            $weights = $this->weightsFor($state, $unitId);
            $moves = array_values(array_filter($commands, static fn (array $c): bool
                => ($c['payload']['unitId'] ?? null) === $unitId && in_array($c['kind'], self::MOVE_KINDS, true)));
            foreach ($this->pruneMoves($state, $legal, $unitId, $moves, 1, $weights) as [$command, $hazardous]) {
                if ($hazardous) {
                    continue;
                }
                // Scored standing where the move leaves it, as planUnit scores a plain move for bestPlanFor.
                $outcome = $this->outcomes($state, $command, 1)[0] ?? null;
                if ($outcome !== null && $this->evaluator->score($outcome, $this->side, $weights) - $this->baseScore($state, $weights) > self::EPSILON) {
                    return $command;
                }
            }
        }
        return null;
    }

    /** @return array{gain:float, plan:array, unitId:string}|null */
    private function bestPlanFor(array $state, array $legal, array $commands, string $unitId, int $destinations): ?array
    {
        $weights = $this->weightsFor($state, $unitId);
        $plans = [];
        $this->planUnit($state, $legal, $commands, $unitId, $weights, $destinations, $plans);
        $forced = $this->hardRule($plans);
        $pool = $forced ?? $plans;
        if ($pool === []) {
            return null;
        }
        usort($pool, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $gain = $pool[0]['score'] - $this->baseScore($state, $weights);
        if ($forced !== null) {
            // A monster with an enemy in reach always takes its swing before the turn ends.
            $gain = max($gain, 10 * self::EPSILON);
        }
        return ['gain' => $gain, 'plan' => $pool[0], 'unitId' => $unitId];
    }

    /**
     * Focus fire: rerank the top units by this unit's gain plus the best gain another top unit has after it.
     * The rerank applies only when every top unit was paired in time, so none is favoured by the clock.
     * @param array<string, array> $results
     */
    private function rerankByPairs(array $state, array $results): array
    {
        $top = array_values(array_filter($results, static fn (array $r): bool => $r['gain'] > self::EPSILON));
        usort($top, static fn (array $a, array $b): int => $b['gain'] <=> $a['gain']);
        $top = array_slice($top, 0, self::PAIR_POOL);
        if (count($top) < 2 || Turn::activationsRemaining($state) < 2) {
            return $results;
        }
        $paired = $results;
        foreach ($top as $first) {
            if ($this->timeUp()) {
                return $results;
            }
            $after = $this->playPlan($state, $first['plan'], $first['unitId']);
            if ($after === null || $after['turn']['activeSeatId'] !== $this->seatId || $after['pending'] !== null) {
                continue;
            }
            $legal = $this->engine->legalActions($after, $this->seatId, $this->nowSeconds);
            $commands = CommandCandidates::for($after, $this->seatId, $legal);
            $partner = 0.0;
            foreach ($top as $second) {
                if ($second['unitId'] === $first['unitId']) {
                    continue;
                }
                $result = $this->bestPlanFor($after, $legal, $commands, $second['unitId'], self::SCAN_DESTINATIONS);
                if ($this->timeUp()) {
                    return $results;
                }
                if ($result !== null) {
                    $partner = max($partner, $result['gain']);
                }
            }
            $paired[$first['unitId']]['gain'] = $first['gain'] + $partner;
        }
        return $paired;
    }

    /** One outcome of a whole plan (first command, its follow-up, then closing the activation). */
    private function playPlan(array $state, array $plan, string $unitId): ?array
    {
        $after = $this->outcomes($state, $plan['command'], 1)[0] ?? null;
        if ($after !== null && isset($plan['follow'])) {
            $after = $this->outcomes($after, $plan['follow'], 1)[0] ?? $after;
        }
        if ($after !== null && $after['turn']['activeUnitId'] === $unitId && $after['pending'] === null) {
            $after = $this->outcomes($after, ['kind' => 'endActivation', 'payload' => ['unitId' => $unitId]], 1)[0] ?? $after;
        }
        return $after;
    }

    /**
     * Scores the unit's plans into $plans as they are found, so a timeout keeps what was scored:
     * each immediate command, and each (pruned) destination alone and with its best follow-up.
     */
    private function planUnit(array $state, array $legal, array $commands, string $unitId, array $weights, int $destinations, array &$plans): void
    {
        $mine = array_values(array_filter($commands, static fn (array $c): bool => ($c['payload']['unitId'] ?? null) === $unitId));
        $immediate = array_values(array_filter($mine, static fn (array $c): bool => in_array($c['kind'], self::IMMEDIATE_KINDS, true)));
        $moves = array_values(array_filter($mine, static fn (array $c): bool => in_array($c['kind'], self::MOVE_KINDS, true)));
        if ($immediate === [] && $moves === []) {
            // A slipping unit can only be activated (its slide comes first).
            $immediate = array_values(array_filter($mine, static fn (array $c): bool => $c['kind'] === 'activateUnit'));
        }
        // Attacks first: if time runs out, the swing in reach has already been scored.
        usort($immediate, static fn (array $a, array $b): int => (int)!self::isAttack($a) <=> (int)!self::isAttack($b));
        foreach ($immediate as $command) {
            if ($this->timeUp()) {
                return;
            }
            $score = $this->meanScore($this->outcomes($state, $command, $this->samples()), $weights);
            if ($score !== null) {
                $plans[] = ['score' => $score, 'command' => $command, 'attack' => self::isAttack($command) && $this->hitsEnemy($state, $unitId, $command)];
            }
        }
        foreach ($this->pruneMoves($state, $legal, $unitId, $moves, $destinations, $weights) as [$command, $hazardous]) {
            if ($this->timeUp()) {
                return;
            }
            $outcomes = $this->outcomes($state, $command, $hazardous || $command['kind'] === 'moveAbility' ? $this->samples() : 1);
            if ($outcomes === []) {
                continue;
            }
            $total = 0.0;
            $attackTotal = 0.0;
            $attackEvery = true;
            $bestFollow = null;
            $bestAttack = null;
            foreach ($outcomes as $outcome) {
                $standing = $this->evaluator->score($outcome, $this->side, $weights);
                [$follow, $followScore, $attack, $attackScore] = $this->followUps($outcome, $unitId, $weights);
                $total += max($standing, $followScore ?? $standing);
                $bestFollow ??= ($followScore !== null && $followScore > $standing) ? $follow : null;
                if ($attackScore === null) {
                    $attackEvery = false;
                } else {
                    $attackTotal += $attackScore;
                    $bestAttack ??= $attack;
                }
            }
            $n = count($outcomes);
            $plans[] = ['score' => $total / $n, 'command' => $command, 'attack' => false] + ($bestFollow !== null ? ['follow' => $bestFollow] : []);
            if ($attackEvery && $bestAttack !== null) {
                $plans[] = ['score' => $attackTotal / $n, 'command' => $command, 'attack' => true, 'follow' => $bestAttack];
            }
        }
    }

    /**
     * The best attack/special/ability the unit can make from where the move left it.
     * @return array{0: ?array, 1: ?float, 2: ?array, 3: ?float} best follow-up and score, best enemy-hitting attack and score
     */
    private function followUps(array $state, string $unitId, array $weights): array
    {
        $none = [null, null, null, null];
        if ($state['phase'] !== 'active' || $state['pending'] !== null || $state['turn']['activeSeatId'] !== $this->seatId
            || $state['turn']['activeUnitId'] !== $unitId || $state['units'][$unitId]['hex'] === null || !$this->enemyWithin($state, $unitId, self::FOLLOW_REACH)) {
            return $none;
        }
        // Only the active unit's options matter here; spending the allowance on paper skips everyone else's movement search.
        $narrow = $state;
        $narrow['turn']['allowance'] = count($state['turn']['activated']);
        $legal = array_values(array_filter(
            $this->engine->legalActions($narrow, $this->seatId, $this->nowSeconds),
            static fn (array $a): bool => ($a['unitId'] ?? null) === $unitId && in_array($a['kind'], self::FOLLOW_KINDS, true)
        ));
        if ($legal === []) {
            return $none;
        }
        $best = $none;
        foreach (CommandCandidates::for($state, $this->seatId, $legal) as $command) {
            if ($this->timeUp()) {
                break;
            }
            $score = $this->meanScore($this->outcomes($state, $command, $this->samples()), $weights);
            if ($score === null) {
                continue;
            }
            if ($best[1] === null || $score > $best[1]) {
                $best[0] = $command;
                $best[1] = $score;
            }
            if (self::isAttack($command) && $this->hitsEnemy($state, $unitId, $command) && ($best[3] === null || $score > $best[3])) {
                $best[2] = $command;
                $best[3] = $score;
            }
        }
        return $best;
    }

    /**
     * Destinations worth simulating: a holding unit stays at its post; the rest are ranked by a cheap
     * heuristic (an enemy in range, then distance to the nearest enemy), with a spread of the others.
     * A personality that values cover also ranks hexes beside a sight blocker ahead: first of all while it
     * holds (distance then means nothing to it), else as if they were COVER_HEXES × cover weight closer.
     * @return list<array{0: array, 1: bool}> command and whether its path crosses a hazard
     */
    private function pruneMoves(array $state, array $legal, string $unitId, array $moves, int $limit, array $weights): array
    {
        if ($moves === []) {
            return [];
        }
        $hazards = [];
        foreach ($legal as $action) {
            if (($action['unitId'] ?? null) === $unitId && in_array($action['kind'], self::MOVE_KINDS, true)) {
                foreach ($action['movementOptions'] ?? [] as $option) {
                    $hazards[$action['kind'] . ':' . $option['destination']] = ($option['hazards'] ?? []) !== [];
                }
            }
        }
        $holding = $this->holdsPost($state, $unitId);
        $enemies = $this->enemyHexes($state);
        $range = max(1, Stats::effective($state, $unitId, 'range'));
        $cover = (float)($weights['cover'] ?? 0);
        $still = (float)($weights['advance'] ?? 0) === 0.0;
        $ranked = [];
        foreach ($moves as $command) {
            $to = (string)($command['payload']['to'] ?? $command['payload']['toHex']);
            if ($holding && !Stance::atPost($this->post, $to)) {
                continue;
            }
            $nearest = PHP_INT_MAX;
            foreach ($enemies as $hex) {
                $nearest = min($nearest, Hex::distance($to, $hex));
            }
            $covered = $cover > 0 && self::besideSightBlocker($state['board'], $to);
            $key = $cover <= 0 ? [$nearest <= $range ? 0 : 1, $nearest]
                : ($still ? [$nearest <= $range ? 0 : 1, $covered ? 0 : 1, $nearest]
                    : [$nearest <= $range ? 0 : 1, $nearest - ($covered ? self::COVER_HEXES * $cover : 0)]);
            $ranked[] = [[...$key, $to, $command['kind']], $command, $hazards[$command['kind'] . ':' . $to] ?? false];
        }
        usort($ranked, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        if (count($ranked) > $limit) {
            $head = (int)ceil($limit * 0.75);
            $rest = array_slice($ranked, $head);
            $spread = [];
            $want = $limit - $head;
            for ($i = 0; $i < $want; $i++) {
                $spread[] = $rest[intdiv($i * count($rest), $want)];
            }
            $ranked = array_merge(array_slice($ranked, 0, $head), $spread);
        }
        return array_map(static fn (array $r): array => [$r[1], $r[2]], $ranked);
    }

    /**
     * Activatable units, most promising first (an enemy in reach, then nearest an enemy), pruned to `candidates`.
     * @return list<string>
     */
    private function rankUnits(array $state, array $commands): array
    {
        $enemies = $this->enemyHexes($state);
        $attacks = [];
        $units = [];
        foreach ($commands as $command) {
            $unitId = $command['payload']['unitId'] ?? null;
            if ($unitId === null || in_array($command['kind'], ['endActivation', 'endTurn', 'resolveChoice'], true)) {
                continue;
            }
            $units[$unitId] = true;
            if (self::isAttack($command) && $this->hitsEnemy($state, (string)$unitId, $command)) {
                $attacks[$unitId] = true;
            }
        }
        $ranked = [];
        foreach (array_keys($units) as $unitId) {
            $unitId = (string)$unitId;
            $hex = (string)$state['units'][$unitId]['hex'];
            $nearest = PHP_INT_MAX;
            foreach ($enemies as $enemy) {
                $nearest = min($nearest, Hex::distance($hex, $enemy));
            }
            $ranked[] = [[isset($attacks[$unitId]) ? 0 : 1, $nearest, $unitId], $unitId];
        }
        usort($ranked, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $ids = array_column($ranked, 1);
        $limit = $this->destinationLimit();
        if (count($ids) <= $limit) {
            return $ids;
        }
        // Mostly the nearest units, but every fourth slot goes to a spread of the rest (farthest first), so a
        // stalled front line cannot leave the far monsters standing for the whole battle.
        $headCount = (int)ceil($limit * 0.75);
        $rest = array_slice($ids, $headCount);
        $want = $limit - $headCount;
        $spread = [];
        for ($i = 0; $i < $want; $i++) {
            $spread[] = $rest[count($rest) - 1 - intdiv($i * count($rest), $want)];
        }
        $head = array_slice($ids, 0, $headCount);
        $order = [];
        while ($head !== [] || $spread !== []) {
            array_push($order, ...array_splice($head, 0, 3));
            if ($spread !== []) {
                $order[] = array_shift($spread);
            }
        }
        return $order;
    }

    /**
     * Hard rule: a unit that can hit an enemy right now never ends its activation without attacking.
     * Returns the attacking plans when the rule applies, else null.
     */
    private function hardRule(array $plans): ?array
    {
        $attackNow = false;
        foreach ($plans as $plan) {
            if ($plan['attack'] && !in_array($plan['command']['kind'], self::MOVE_KINDS, true)) {
                $attackNow = true;
                break;
            }
        }
        if (!$attackNow) {
            return null;
        }
        // Holding units only ever see destinations at their post, so no attack plan leaves it.
        return array_values(array_filter($plans, static fn (array $p): bool => $p['attack']));
    }

    /** Best plan by score (first listed wins ties), or the runner-up on a blunder. */
    private function pick(array $plans, SeededRandom $dice): ?array
    {
        if ($plans === []) {
            return null;
        }
        // One entry per command, keeping its best score, so a blunder picks a different command.
        $best = [];
        foreach ($plans as $plan) {
            $key = $plan['command']['kind'] . json_encode($plan['command']['payload']);
            if (!isset($best[$key]) || $plan['score'] > $best[$key]['score']) {
                $best[$key] = $plan;
            }
        }
        $ranked = array_values($best);
        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return count($ranked) > 1 && $this->blunders($dice) ? $ranked[1] : $ranked[0];
    }

    private function blunders(SeededRandom $dice): bool
    {
        $chance = (float)($this->personality->sharpness['blunder'] ?? 0);
        return $chance > 0 && $dice->roll(10000) <= $chance * 10000;
    }

    private function weightsFor(array $state, string $unitId): array
    {
        return $this->holdsPost($state, $unitId) ? Stance::holdWeights($this->personality) : Stance::attackWeights($this->personality);
    }

    /** Holding applies only to units standing within reach of their post. */
    private function holdsPost(array $state, string $unitId): bool
    {
        return $this->holding && Stance::atPost($this->post, $state['units'][$unitId]['hex'] ?? null);
    }

    private function baseScore(array $state, array $weights): float
    {
        // Keyed by the units too: the pair lookahead scores units on the board after another's plan.
        $key = json_encode($weights) . crc32(serialize($state['units']));
        return $this->baseCache[$key] ??= $this->evaluator->score($state, $this->side, $weights);
    }

    private function outcomes(array $state, array $command, int $samples): array
    {
        return $this->simulator->outcomes($state, $this->seatId, $command['kind'], $command['payload'], $samples, $this->seed);
    }

    private function meanScore(array $outcomes, array $weights): ?float
    {
        if ($outcomes === []) {
            return null;
        }
        $total = 0.0;
        foreach ($outcomes as $outcome) {
            $total += $this->evaluator->score($outcome, $this->side, $weights);
        }
        return $total / count($outcomes);
    }

    private function samples(): int
    {
        return max(1, (int)($this->personality->sharpness['samples'] ?? 1));
    }

    /** Search stops with a third of the step's budget to spare, for the work around it (legal actions, the reply). */
    private function timeUp(): bool
    {
        if (!$this->expired && ($this->clock)() >= $this->searchUntilMs) {
            $this->expired = true;
        }
        return $this->expired;
    }

    /** `sharpness.candidates`: units considered and destinations searched per unit. */
    private function destinationLimit(): int
    {
        return max(1, (int)($this->personality->sharpness['candidates'] ?? 8));
    }

    /** How many scanned units get the full destination search: sharper personalities look wider. */
    private function refineCount(): int
    {
        return max(1, intdiv($this->destinationLimit(), 8));
    }

    /** @return list<string> hexes of living enemy units (the portal and other fixed pieces included) */
    private function enemyHexes(array $state): array
    {
        $hexes = [];
        foreach ($state['units'] as $id => $unit) {
            if ($unit['hex'] !== null && Sides::ofUnit($state, (string)$id) !== $this->side) {
                $hexes[] = (string)$unit['hex'];
            }
        }
        return $hexes;
    }

    private function enemyWithin(array $state, string $unitId, int $reach): bool
    {
        $from = (string)$state['units'][$unitId]['hex'];
        foreach ($this->enemyHexes($state) as $hex) {
            if (Hex::distance($from, $hex) <= $reach) {
                return true;
            }
        }
        return false;
    }

    private function hitsEnemy(array $state, string $unitId, array $command): bool
    {
        $targets = $command['kind'] === 'attackUnit' ? [$command['payload']['targetUnitId']] : ($command['payload']['targetUnitIds'] ?? []);
        foreach ($targets as $target) {
            if (isset($state['units'][$target]) && Sides::ofUnit($state, (string)$target) !== $this->side) {
                return true;
            }
        }
        return false;
    }

    private static function besideSightBlocker(array $board, string $hex): bool
    {
        foreach (Hex::neighbors($hex, $board) as $near) {
            if ($board[$near]['sightBlocker'] ?? false) {
                return true;
            }
        }
        return false;
    }

    private static function isAttack(array $command): bool
    {
        return in_array($command['kind'], ['attackUnit', 'specialAttack'], true);
    }

    private static function find(array $commands, string $kind): ?array
    {
        foreach ($commands as $command) {
            if ($command['kind'] === $kind) {
                return $command;
            }
        }
        return null;
    }

    /** Nothing scored in time: close the activation, else the turn, else the first legal expansion. */
    private static function fallback(array $commands): array
    {
        return self::find($commands, 'endActivation') ?? self::find($commands, 'endTurn') ?? $commands[0];
    }
}
