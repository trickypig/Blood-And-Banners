<?php
declare(strict_types=1);

namespace BloodAndBanners\Ai;

use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\Modes\CoopSetup;
use BloodAndBanners\Rules\Vanilla\Terrain;

/**
 * Plans the Void Lord's prep placements for a personality.
 *
 * It sees only the board, the legal placement list and the budget, never the draft, so it cannot
 * react to hero picks. The legal list reflects the state before the plan, so the plan tracks the hexes
 * it has taken and the budget it has spent itself.
 */
final class HordeBuilder
{
    private const TENTACLES = ['Kraken Tentacle (Lesser)', 'Kraken Tentacle (Greater)'];
    /** Mirrors CoopSetup::canPlace, which refuses a fifth Kraken tentacle in prep. */
    private const TENTACLE_LIMIT = 4;

    public function __construct(private readonly Catalog $catalog)
    {
    }

    /**
     * @param array<string,array> $board
     * @param array<string,list<string>> $placeable monster name => legal spawn hexes
     * @return list<array{monster:string,hex:string}>
     */
    public function plan(array $board, array $placeable, int $budget, int $heroPlayers, Personality $p, int $seed): array
    {
        $info = [];
        foreach ($placeable as $name => $hexes) {
            $monster = $this->catalog->monster((string)$name);
            if ($monster !== null && $hexes !== []) {
                $info[(string)$name] = [
                    'cost' => CoopSetup::cost($monster),
                    'tier' => (string)$monster['tier'],
                    'ranged' => (int)$monster['range'] > 1,
                    'biome' => (string)$monster['biome'],
                    'hexes' => array_values($hexes),
                    'set' => array_flip($hexes),
                ];
            }
        }
        $spawn = [];
        foreach ($info as $entry) {
            foreach ($entry['hexes'] as $hex) {
                $spawn[$hex] = true;
            }
        }
        if ($spawn === [] || $budget <= 0) {
            return [];
        }
        ksort($spawn, SORT_STRING);
        $ctx = $this->context($board, array_keys($spawn));
        $regions = $this->regions($ctx);
        $order = $this->rank($regions, $ctx, $p->horde['placement'] ?? 'forward');

        $s = [
            'ctx' => $ctx, 'info' => $info, 'p' => $p, 'rng' => new SeededRandom($seed),
            'remaining' => $budget, 'budget' => $budget, 'taken' => [], 'plan' => [], 'tentacles' => 0,
            'spent' => ['common' => 0, 'elite' => 0, 'boss' => 0], 'used' => [], 'frontier' => [],
        ];
        $packages = HordePackages::all();
        $stalled = 0;
        for ($visit = 0; $s['remaining'] > 0 && $stalled < count($order) && $visit < 400; $visit++) {
            $free = count($ctx['spawn']) - count($s['taken']);
            // A package always comes first; after that, if hexes are the scarce resource, leave the rest to the dearer fillers.
            if ($free <= 0 || ($s['plan'] !== [] && intdiv($s['remaining'] + $free - 1, $free) >= 3)) {
                break;
            }
            $region = $regions[$order[$visit % count($order)]];
            $stalled = $this->placePackage($s, $region, $packages) ? 0 : $stalled + 1;
        }
        $this->fill($s);
        return $s['plan'];
    }

    /** Hex coordinates, biomes, hero distances and cover flags for the spawn hexes. */
    private function context(array $board, array $spawn): array
    {
        $xy = [];
        $starts = [];
        $portal = null;
        foreach ($board as $key => $hex) {
            $key = (string)$key;
            if (($hex['playerStart'] ?? null) !== null) {
                $starts[] = $this->coords($key);
            }
            if ($portal === null && (($hex['voidPortal'] ?? false) || ($hex['portal'] ?? false))) {
                $portal = $this->coords($key);
            }
        }
        $far = null;
        $hero = $cover = $biomes = [];
        foreach ($spawn as $key) {
            $xy[$key] = $this->coords($key);
            $hero[$key] = $starts === [] ? 0 : min(array_map(fn (array $s): int => $this->dist($s, $xy[$key]), $starts));
        }
        if ($portal === null) {
            // Mirrors the engine's fallback: the spawn hex farthest from the heroes.
            foreach ($spawn as $key) {
                if ($far === null || $hero[$key] >= $hero[$far]) {
                    $far = $key;
                }
            }
            $portal = $xy[$far];
        }
        foreach ($spawn as $key) {
            $tile = $board[$key];
            $biomes[$key] = BiomeMap::monsterBiomes((string)($tile['biome'] ?? 'plains'), (string)($tile['terrain'] ?? 'grass'));
            $cover[$key] = false;
            foreach (Hex::neighbors($key, $board) as $n) {
                $near = $board[$n];
                if (($near['sightBlocker'] ?? false) || ($near['impassable'] ?? false) || Terrain::type($near) === 'water') {
                    $cover[$key] = true;
                    break;
                }
            }
        }
        return [
            'board' => $board, 'spawn' => $spawn, 'xy' => $xy, 'hero' => $hero, 'cover' => $cover, 'biomes' => $biomes,
            'portal' => array_combine($spawn, array_map(fn (string $k): int => $this->dist($portal, $xy[$k]), $spawn)),
        ];
    }

    /** Connected components of spawn hexes that share their first monster biome. @return list<list<string>> */
    private function regions(array $ctx): array
    {
        $seen = [];
        $regions = [];
        $spawn = array_flip($ctx['spawn']);
        foreach ($ctx['spawn'] as $start) {
            if (isset($seen[$start])) {
                continue;
            }
            $theme = $ctx['biomes'][$start][0] ?? '';
            $seen[$start] = true;
            $queue = [$start];
            $region = [];
            while ($queue !== []) {
                $hex = array_pop($queue);
                $region[] = $hex;
                foreach (Hex::neighbors($hex, $ctx['board']) as $n) {
                    if (isset($spawn[$n]) && !isset($seen[$n]) && ($ctx['biomes'][$n][0] ?? '') === $theme) {
                        $seen[$n] = true;
                        $queue[] = $n;
                    }
                }
            }
            sort($region, SORT_STRING);
            $regions[] = $region;
        }
        return $regions;
    }

    /** @return list<int> region indexes in the order the personality visits them */
    private function rank(array $regions, array $ctx, string $placement): array
    {
        $score = static function (array $region, string $metric) use ($ctx): int {
            if ($metric === 'cover') {
                return -count(array_filter($region, static fn (string $h): bool => $ctx['cover'][$h]));
            }
            return min(array_map(static fn (string $h): int => $ctx[$metric][$h], $region));
        };
        $by = static function (string $metric) use ($regions, $score): array {
            $idx = array_keys($regions);
            usort($idx, static fn (int $a, int $b): int => [$score($regions[$a], $metric), $a] <=> [$score($regions[$b], $metric), $b]);
            return $idx;
        };
        return match ($placement) {
            'portal' => $by('portal'),
            'cover' => $by('cover'),
            'combo' => $this->interleave($by('hero'), $by('portal')),
            default => $by('hero'),
        };
    }

    private function interleave(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $i => $x) {
            foreach ([$x, $b[$i]] as $r) {
                if (!in_array($r, $out, true)) {
                    $out[] = $r;
                }
            }
        }
        return $out;
    }

    /** Places one themed package in a region. Returns whether anything was placed. */
    private function placePackage(array &$s, array $region, array $packages): bool
    {
        $p = $s['p'];
        $rng = $s['rng'];
        $regionSet = array_flip($region);
        $fit = [];
        foreach ($region as $hex) {
            foreach ($s['ctx']['biomes'][$hex] as $biome) {
                $fit[$biome] = true;
            }
        }
        $onBiome = $offBiome = [];
        foreach ($packages as $i => $package) {
            $core = $package['creatures'][0];
            if (!$this->fits($s, $core, $regionSet, $s['remaining'])) {
                continue;
            }
            if (isset($fit[$package['biome']])) {
                $onBiome[] = $i;
            } else {
                $offBiome[] = $i;
            }
        }
        if ($onBiome === [] && $offBiome === []) {
            return false;
        }
        $offChance = (int)round(1000 * (float)($p->horde['offBiome'] ?? 0));
        $off = $offChance > 0 && $rng->roll(1000) <= $offChance;
        $pool = $off && $offBiome !== [] ? $offBiome : ($onBiome !== [] ? $onBiome : $offBiome);

        $free = count($s['ctx']['spawn']) - count($s['taken']);
        $need = $free > 0 ? min(4, intdiv($s['remaining'] + $free - 1, $free)) : 1;
        $dear = array_values(array_filter($pool, fn (int $i): bool => $s['info'][$packages[$i]['creatures'][0]]['cost'] >= $need));
        $pool = $dear !== [] ? $dear : $pool;
        $shares = $p->horde['shares'];
        $wanted = array_values(array_filter($pool, fn (int $i): bool => ($shares[$s['info'][$packages[$i]['creatures'][0]]['tier']] ?? 0) > 0));
        $pool = $wanted !== [] ? $wanted : $pool;
        $tagged = array_values(array_filter($pool, static fn (int $i): bool => in_array($p->horde['tag'] ?? '', $packages[$i]['tags'], true)));
        $unused = array_values(array_filter($pool, static fn (int $i): bool => !isset($s['used'][$i])));
        $both = array_values(array_intersect($tagged, $unused));
        foreach ([$both, $tagged, $unused, $pool] as $tier) {
            if ($tier !== []) {
                $pool = $tier;
                break;
            }
        }
        $pick = $pool[$rng->roll(count($pool)) - 1];
        $s['used'][$pick] = true;

        // Scale to the remaining budget by dropping trailing creatures, keeping to the tier shares.
        $chosen = [];
        $left = $s['remaining'];
        $tentacles = $s['tentacles'];
        $tierSpent = $s['spent'];
        foreach ($packages[$pick]['creatures'] as $n => $name) {
            $e = $s['info'][$name] ?? null;
            if ($e === null || $e['cost'] > $left) {
                continue;
            }
            if ($n > 0 && $e['cost'] < $need) {
                continue; // hexes are short: spend them on dearer members
            }
            if ($n > 0 && $tierSpent[$e['tier']] >= ($shares[$e['tier']] ?? 0) * $s['budget']) {
                continue;
            }
            if (in_array($name, self::TENTACLES, true)) {
                if ($tentacles >= self::TENTACLE_LIMIT) {
                    continue;
                }
                $tentacles++;
            }
            $chosen[] = $name;
            $left -= $e['cost'];
            $tierSpent[$e['tier']] += $e['cost'];
        }
        $core = array_shift($chosen);
        $melee = array_values(array_filter($chosen, fn (string $n): bool => !$s['info'][$n]['ranged']));
        $ranged = array_values(array_filter($chosen, fn (string $n): bool => $s['info'][$n]['ranged']));

        $coreHex = $this->take($s, $core, $regionSet, null);
        if ($coreHex === null) {
            return false;
        }
        $front = null;
        foreach ($melee as $name) {
            $hex = $this->take($s, $name, $regionSet, $coreHex, null);
            if ($hex !== null) {
                $front = max($front ?? 0, $s['ctx']['hero'][$hex]);
            }
        }
        foreach ($ranged as $name) {
            $this->take($s, $name, $regionSet, $coreHex, $front);
        }
        return true;
    }

    private function fits(array $s, string $name, array $regionSet, int $remaining): bool
    {
        $e = $s['info'][$name] ?? null;
        if ($e === null || $e['cost'] > $remaining) {
            return false;
        }
        if (in_array($name, self::TENTACLES, true) && $s['tentacles'] >= self::TENTACLE_LIMIT) {
            return false;
        }
        foreach ($e['hexes'] as $hex) {
            if (isset($regionSet[$hex]) && !isset($s['taken'][$hex])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Places a creature in the region: the core on the hex farthest from the heroes, followers close to it
     * (melee toward the heroes, ranged behind). Returns the hex, or null when nothing fits.
     */
    private function take(array &$s, string $name, array $regionSet, ?string $coreHex, ?int $behind = null): ?string
    {
        $e = $s['info'][$name];
        if (!$this->fits($s, $name, $regionSet, $s['remaining'])) {
            return null;
        }
        $ctx = $s['ctx'];
        $cover = ($s['p']->horde['placement'] ?? '') === 'cover';
        $best = null;
        $bestKey = null;
        foreach ($e['hexes'] as $hex) {
            if (!isset($regionSet[$hex]) || isset($s['taken'][$hex])) {
                continue;
            }
            if ($coreHex === null) {
                $key = [0, $cover && $ctx['cover'][$hex] ? 0 : 1, -$ctx['hero'][$hex], $hex];
            } else {
                $near = $this->dist($ctx['xy'][$coreHex], $ctx['xy'][$hex]);
                $key = [$behind !== null && $ctx['hero'][$hex] < $behind ? 1 : 0, $cover && $ctx['cover'][$hex] ? 0 : 1, $near, $e['ranged'] ? -$ctx['hero'][$hex] : $ctx['hero'][$hex], $hex];
            }
            if ($bestKey === null || $key < $bestKey) {
                $best = $hex;
                $bestKey = $key;
            }
        }
        $this->commit($s, $name, $best);
        return $best;
    }

    private function commit(array &$s, string $name, string $hex): void
    {
        $e = $s['info'][$name];
        $s['plan'][] = ['monster' => $name, 'hex' => $hex];
        $s['taken'][$hex] = true;
        $s['remaining'] -= $e['cost'];
        $s['spent'][$e['tier']] += $e['cost'];
        if (in_array($name, self::TENTACLES, true)) {
            $s['tentacles']++;
        }
        foreach (Hex::neighbors($hex, $s['ctx']['board']) as $n) {
            $s['frontier'][$n] = true;
        }
    }

    /** Spends what is left on the cheapest creatures, on theme where possible, next to what is already placed. */
    private function fill(array &$s): void
    {
        $ctx = $s['ctx'];
        $names = array_keys($s['info']);
        usort($names, fn (string $a, string $b): int => [$s['info'][$a]['cost'], $a] <=> [$s['info'][$b]['cost'], $b]);
        $cover = ($s['p']->horde['placement'] ?? '') === 'cover';
        while ($s['remaining'] > 0) {
            $found = null;
            // When hexes run short, only creatures dear enough to still spend the budget will do.
            $free = count($ctx['spawn']) - count($s['taken']);
            $need = $free > 0 ? min(4, intdiv($s['remaining'] + $free - 1, $free)) : 1;
            foreach ([[true, $need], [false, $need], [true, 1], [false, 1]] as [$onTheme, $least]) {
                foreach ($names as $name) {
                    $e = $s['info'][$name];
                    if ($e['cost'] > $s['remaining'] || $e['cost'] < $least || (in_array($name, self::TENTACLES, true) && $s['tentacles'] >= self::TENTACLE_LIMIT)) {
                        continue;
                    }
                    $best = null;
                    $bestKey = null;
                    foreach ($e['hexes'] as $hex) {
                        if (isset($s['taken'][$hex]) || ($onTheme && !in_array($e['biome'], $ctx['biomes'][$hex], true))) {
                            continue;
                        }
                        $key = [isset($s['frontier'][$hex]) ? 0 : 1, $cover && $ctx['cover'][$hex] ? 0 : 1, $hex];
                        if ($bestKey === null || $key < $bestKey) {
                            $best = $hex;
                            $bestKey = $key;
                        }
                    }
                    if ($best !== null) {
                        $found = [$name, $best];
                        break 2;
                    }
                }
            }
            if ($found === null) {
                return;
            }
            $this->commit($s, $found[0], $found[1]);
        }
    }

    /** @return array{0:int,1:int} */
    private function coords(string $key): array
    {
        return Hex::parse($key);
    }

    private function dist(array $a, array $b): int
    {
        $dq = $b[0] - $a[0];
        $dr = $b[1] - $a[1];
        return intdiv(abs($dq) + abs($dr) + abs($dq + $dr), 2);
    }
}
