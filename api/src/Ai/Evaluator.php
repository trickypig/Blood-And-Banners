<?php
declare(strict_types=1);

namespace BloodAndBanners\Ai;

use BloodAndBanners\Rules\HexLineOfSight;
use BloodAndBanners\Rules\Vanilla\Combat;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\Modes\CoopMode;
use BloodAndBanners\Rules\Vanilla\Modes\CoopSetup;
use BloodAndBanners\Rules\Vanilla\Movement;
use BloodAndBanners\Rules\Vanilla\Sides;
use BloodAndBanners\Rules\Vanilla\Stats;

/** Scores a position for one side as a weighted sum of roughly 0..1 features. Reads state only. */
final class Evaluator
{
    private const TERMINAL = 1e6;
    private const SIGHT_CAP = 10;
    private const NEIGHBOURS = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]];

    /** @var array<string, array{0:int, 1:int}> parsed hex keys */
    private static array $coords = [];

    /** @var array<string, array<string, true>> hexes an enemy can strike this turn, by unit, hex and modifiers, when memoised */
    private array $zones = [];
    /** @var array<string, array<string, true>> */
    private array $unions = [];
    /** @var array<string, bool> sight between two hexes, when memoised */
    private array $sight = [];

    /**
     * $memoReach keeps each enemy's strike zone per (unit, hex, effects and conditions) and sight per hex pair for the
     * life of this instance. That ignores units that moved into (or out of) the way since, which is fine
     * inside one planning step: create a fresh instance per step.
     */
    public function __construct(private readonly Personality $personality, private readonly bool $memoReach = false)
    {
    }

    public function score(array $state, string $side, ?array $weights = null): float
    {
        $weights ??= $this->personality->weights;
        $won = self::winner($state);
        if ($won !== null) {
            return $won === $side ? self::TERMINAL : -self::TERMINAL;
        }

        $ours = [];
        $theirs = [];
        $portalHex = null;
        foreach ($state['units'] as $id => $unit) {
            $id = (string)$id;
            if ($id === CoopMode::VOID_PORTAL_ID) {
                $portalHex = $unit['hex'];
                continue;
            }
            if (!empty($unit['fixed'])) {
                continue;
            }
            if (Sides::ofUnit($state, $id) === $side) {
                $ours[$id] = $unit;
            } else {
                $theirs[$id] = $unit;
            }
        }

        $want = static fn(string $key): bool => (float)($weights[$key] ?? 0) !== 0.0;
        $f = ['damage' => 0.0, 'kill' => 0.0, 'loss' => 0.0, 'portalGuard' => 0.0, 'advance' => 0.0,
            'cohesion' => 0.0, 'cover' => 0.0, 'threat' => 0.0, 'focus' => 0.0];

        if ($want('damage') || $want('focus') || $want('kill')) {
            $enemyHp = 0;
            $enemyWounds = 0;
            $dead = 0;
            $maxShare = 0.0;
            foreach ($theirs as $unit) {
                $hp = max(1, (int)$unit['hp']);
                $w = (int)($unit['wounds'] ?? 0);
                $enemyHp += $hp;
                $enemyWounds += min($w, $hp);
                $maxShare = max($maxShare, min(1.0, $w / $hp));
                if ($unit['hex'] === null) {
                    $dead++;
                }
            }
            $ourHp = 0;
            $ourWounds = 0;
            foreach ($ours as $unit) {
                $hp = max(1, (int)$unit['hp']);
                $ourHp += $hp;
                $ourWounds += min((int)($unit['wounds'] ?? 0), $hp);
            }
            // Zero-sum: wounds dealt minus wounds taken, each as a share of that side's total HP.
            $f['damage'] = ($enemyHp > 0 ? $enemyWounds / $enemyHp : 0.0) - ($ourHp > 0 ? $ourWounds / $ourHp : 0.0);
            $f['kill'] = $theirs ? $dead / count($theirs) : 0.0;
            $f['focus'] = $maxShare;
        }

        if ($want('loss')) {
            $lostCost = 0;
            $totalCost = 0;
            foreach ($ours as $unit) {
                $cost = !empty($unit['isHero']) ? 4 : CoopSetup::cost($unit);
                $totalCost += $cost;
                if ($unit['hex'] === null) {
                    $lostCost += $cost;
                }
            }
            $f['loss'] = $totalCost > 0 ? $lostCost / $totalCost : 0.0;
        }

        $ourOn = array_filter($ours, static fn(array $u): bool => $u['hex'] !== null);
        $theirOn = array_filter($theirs, static fn(array $u): bool => $u['hex'] !== null);
        $n = count($ourOn);
        if ($n > 0) {
            $distSum = 0.0;
            $cohesive = 0;
            $hidden = 0;
            $wantCohesion = $want('cohesion');
            $wantCover = $want('cover');
            $wantAdvance = $want('advance');
            $sight = $wantCover ? Combat::sightBoard($state) : [];
            $lineOfSight = new HexLineOfSight();
            $enemyAt = [];
            foreach ($theirOn as $enemy) {
                $enemyAt[] = [(string)$enemy['hex'], ...self::coords((string)$enemy['hex'])];
            }
            $ourHexes = [];
            foreach ($ourOn as $unit) {
                $ourHexes[(string)$unit['hex']] = true;
            }
            foreach ($wantAdvance || $wantCover || $wantCohesion ? $ourOn : [] as $unit) {
                $hex = (string)$unit['hex'];
                [$q, $r] = self::coords($hex);
                $nearest = null;
                $seen = false;
                foreach ($wantAdvance || $wantCover ? $enemyAt : [] as [$enemyHex, $eq, $er]) {
                    $gap = (abs($q - $eq) + abs($r - $er) + abs($q + $r - $eq - $er)) / 2;
                    $nearest = $nearest === null ? $gap : min($nearest, $gap);
                    // Beyond SIGHT_CAP nothing there is a threat, and long open-board sight checks are costly.
                    if ($wantCover && !$seen && $gap <= self::SIGHT_CAP && ($gap === 0 || $this->sees($lineOfSight, $sight, $enemyHex, $hex))) {
                        $seen = true;
                    }
                }
                $distSum += $wantAdvance ? ($nearest ?? 0) : 0;
                if ($wantCohesion) {
                    foreach (self::NEIGHBOURS as [$dq, $dr]) {
                        if (isset($ourHexes[($q + $dq) . ',' . ($r + $dr)])) {
                            $cohesive++;
                            break;
                        }
                    }
                }
                $hidden += $wantCover && !$seen ? 1 : 0;
            }
            // Unclamped: on a big board the mean starts far above 10 hexes, and every step closer must still count.
            $f['advance'] = -($distSum / $n) / 10.0;
            $f['cohesion'] = $cohesive / $n;
            $f['cover'] = $hidden / $n;

            $zones = [];
            // Move and range come from effects and conditions; while those stand still, so does each zone.
            $modifiers = $this->memoReach && $want('threat') ? crc32(serialize([$state['effects'] ?? [], $state['conditions'] ?? []])) : 0;
            foreach ($want('threat') ? $theirOn : [] as $eid => $enemy) {
                $zones[] = $this->dangerZone($state, (string)$eid, (string)$enemy['hex'], $modifiers);
            }
            $threatened = 0;
            if ($zones !== []) {
                $danger = $this->union($zones);
                foreach ($ourOn as $unit) {
                    $threatened += isset($danger[(string)$unit['hex']]) ? 1 : 0;
                }
            }
            $f['threat'] = -$threatened / $n;
        }

        if ($side === 'void' && $portalHex !== null && $want('portalGuard')) {
            $near = 0;
            foreach ($ourOn as $unit) {
                $near += Hex::distance((string)$unit['hex'], (string)$portalHex) <= 3 ? 1 : 0;
            }
            foreach ($theirOn as $unit) {
                $near -= Hex::distance((string)$unit['hex'], (string)$portalHex) <= 3 ? 1 : 0;
            }
            $f['portalGuard'] = $near / max(1, count($ourOn) + count($theirOn));
        }

        $total = 0.0;
        foreach ($f as $key => $value) {
            $total += (float)($weights[$key] ?? 0) * $value;
        }
        return $total;
    }

    /** Hexes within the enemy's range of somewhere it can move to (its own hex included). */
    private function dangerZone(array $state, string $enemyId, string $hex, int $modifiers): array
    {
        $key = "{$enemyId}@{$hex}/{$modifiers}";
        if ($this->memoReach && isset($this->zones[$key])) {
            return ['key' => $key, 'hexes' => $this->zones[$key]];
        }
        $move = Stats::effective($state, $enemyId, 'move');
        $range = max(1, (int)Stats::effective($state, $enemyId, 'range'));
        $offsets = [];
        for ($dq = -$range; $dq <= $range; $dq++) {
            for ($dr = max(-$range, -$dq - $range); $dr <= min($range, -$dq + $range); $dr++) {
                $offsets[] = [$dq, $dr];
            }
        }
        $zone = [];
        foreach ([...Movement::reachable($state, $enemyId, $move), $hex] as $from) {
            [$q, $r] = Hex::parse($from);
            foreach ($offsets as [$dq, $dr]) {
                $zone[($q + $dq) . ',' . ($r + $dr)] = true;
            }
        }
        if ($this->memoReach) {
            $this->zones[$key] = $zone;
        }
        return ['key' => $key, 'hexes' => $zone];
    }

    /** @param list<array{key:string, hexes:array<string,true>}> $zones */
    private function union(array $zones): array
    {
        $key = implode('|', array_column($zones, 'key'));
        if ($this->memoReach && isset($this->unions[$key])) {
            return $this->unions[$key];
        }
        $danger = array_replace(...array_column($zones, 'hexes'));
        if ($this->memoReach) {
            $this->unions[$key] = $danger;
        }
        return $danger;
    }

    private function sees(HexLineOfSight $lineOfSight, array $sight, string $from, string $to): bool
    {
        if (!$this->memoReach) {
            return $lineOfSight->isVisible($from, $to, $sight);
        }
        return $this->sight["{$from}>{$to}"] ??= $lineOfSight->isVisible($from, $to, $sight);
    }

    /** @return array{0:int, 1:int} */
    private static function coords(string $hex): array
    {
        return self::$coords[$hex] ??= Hex::parse($hex);
    }

    private static function winner(array $state): ?string
    {
        $result = $state['result'] ?? null;
        if (!is_array($result)) {
            return null;
        }
        if (isset($result['winningSide'])) {
            return (string)$result['winningSide'];
        }
        if (isset($result['winningSeatId'])) {
            $seat = (string)$result['winningSeatId'];
            foreach ($state['sides'] ?? [] as $sideId => $side) {
                if (in_array($seat, $side['seatIds'] ?? [], true)) {
                    return (string)$sideId;
                }
            }
        }
        return null;
    }
}
