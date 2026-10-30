<?php
declare(strict_types=1);

namespace BloodAndBanners\Ai;

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

    public function __construct(private readonly Personality $personality)
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
            foreach ($ourOn as $id => $unit) {
                $nearest = null;
                if ($wantAdvance) {
                    foreach ($theirOn as $enemy) {
                        $d = Hex::distance((string)$unit['hex'], (string)$enemy['hex']);
                        $nearest = $nearest === null ? $d : min($nearest, $d);
                    }
                }
                $distSum += $nearest ?? 0;
                if ($wantCohesion) {
                    foreach ($ourOn as $otherId => $other) {
                        if ($otherId !== $id && Hex::distance((string)$unit['hex'], (string)$other['hex']) === 1) {
                            $cohesive++;
                            break;
                        }
                    }
                }
                if ($wantCover) {
                    $seen = false;
                    foreach ($theirOn as $enemy) {
                        // Beyond SIGHT_CAP the ray enumeration explodes on open ground and nothing there is a threat.
                        $gap = Hex::distance((string)$enemy['hex'], (string)$unit['hex']);
                        if ($gap <= self::SIGHT_CAP && ($gap === 0 || Combat::visible($state, (string)$enemy['hex'], (string)$unit['hex']))) {
                            $seen = true;
                            break;
                        }
                    }
                    $hidden += $seen ? 0 : 1;
                }
            }
            $f['advance'] = -min(1.0, ($distSum / $n) / 10.0);
            $f['cohesion'] = $cohesive / $n;
            $f['cover'] = $hidden / $n;

            $reach = [];
            foreach ($want('threat') ? $theirOn : [] as $eid => $enemy) {
                $move = Stats::effective($state, (string)$eid, 'move');
                $range = max(1, (int)Stats::effective($state, (string)$eid, 'range'));
                $hexes = Movement::reachable($state, (string)$eid, $move);
                $hexes[] = (string)$enemy['hex'];
                $reach[] = [$hexes, $range];
            }
            $threatened = 0;
            foreach ($reach ? $ourOn : [] as $unit) {
                foreach ($reach as [$hexes, $range]) {
                    foreach ($hexes as $h) {
                        if (Hex::distance($h, (string)$unit['hex']) <= $range) {
                            $threatened++;
                            continue 3;
                        }
                    }
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
