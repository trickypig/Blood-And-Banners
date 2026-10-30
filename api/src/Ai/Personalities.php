<?php
declare(strict_types=1);

namespace BloodAndBanners\Ai;

final class Personalities
{
    /** @var array<string, Personality>|null */
    private static ?array $all = null;

    /**
     * Tuning note (soak, 2026-10-30): `advance` scores one hex of approach at a tenth of what one unit flipping a
     * binary feature (threat, cohesion, cover) scores. Wherever such a feature outweighs advance × move / 10, a
     * move-2 monster never steps into the heroes' reach, out of its pack or out of cover, and the battle stalls.
     * So attack-mode advance stays well above threat and cohesion. Personality lives in the stance, the horde,
     * portalGuard and loss (warden), cover and focus (huntress), and raw advance (ravager, the highest).
     * @return array<string, Personality> keyed in difficulty order
     */
    public static function all(): array
    {
        return self::$all ??= [
            'ravager' => new Personality(
                'ravager', 'The Ravager', 'easy',
                'All-out aggression: masses of cheap commons rush the heroes and chase the weak.',
                ['damage' => 1.0, 'kill' => 3, 'loss' => 0.4, 'material' => 0.3, 'portalGuard' => 0, 'advance' => 1.0, 'cohesion' => 0.05, 'cover' => 0, 'threat' => 0.05, 'focus' => 0.5],
                ['shares' => ['common' => .75, 'elite' => .25, 'boss' => 0.0], 'placement' => 'forward', 'tag' => 'swarm', 'offBiome' => 0.1],
                ['candidates' => 8, 'samples' => 1, 'blunder' => 0.25, 'pairs' => false],
                ['mode' => 'attack'],
            ),
            'warden' => new Personality(
                'warden', 'The Warden', 'medium',
                'Fortress: elites and Unmovable pieces ring the portal, holding ground and punishing overextension.',
                ['damage' => 0.8, 'kill' => 3, 'loss' => 1.5, 'material' => 0.3, 'portalGuard' => 2.0, 'advance' => 0.8, 'cohesion' => 0.1, 'cover' => 0.2, 'threat' => 0.15, 'focus' => 0.5],
                ['shares' => ['common' => .25, 'elite' => .5, 'boss' => .25], 'placement' => 'portal', 'tag' => 'fortress', 'offBiome' => 0.1],
                ['candidates' => 16, 'samples' => 2, 'blunder' => 0.10, 'pairs' => false],
                ['mode' => 'hold', 'untilHeroWithin' => 6, 'untilRound' => 3],
            ),
            'huntress' => new Personality(
                'huntress', 'The Huntress', 'hard',
                'Ambush: hides behind cover and in water, lures heroes forward, then strikes the flanks one hero at a time.',
                ['damage' => 1.0, 'kill' => 4, 'loss' => 1.0, 'material' => 0.3, 'portalGuard' => 0.5, 'advance' => 0.8, 'cohesion' => 0.1, 'cover' => 1.0, 'threat' => 0.05, 'focus' => 2.0],
                ['shares' => ['common' => .4, 'elite' => .45, 'boss' => .15], 'placement' => 'cover', 'tag' => 'ambush', 'offBiome' => 0.2],
                ['candidates' => 24, 'samples' => 3, 'blunder' => 0.05, 'pairs' => true],
                ['mode' => 'holdUntilReach', 'untilRound' => 2],
            ),
            'hollowKing' => new Personality(
                'hollowKing', 'The Hollow King', 'hardest',
                'Adaptive: patient at first, aggressive once ahead, with tight biome combos and a step of lookahead.',
                ['damage' => 1.0, 'kill' => 4, 'loss' => 1.0, 'material' => 0.3, 'portalGuard' => 0.8, 'advance' => 0.5, 'cohesion' => 0.25, 'cover' => 0.3, 'threat' => 0.15, 'focus' => 1.0],
                ['shares' => ['common' => .3, 'elite' => .4, 'boss' => .3], 'placement' => 'combo', 'tag' => 'combo', 'offBiome' => 0.4],
                ['candidates' => 40, 'samples' => 4, 'blunder' => 0, 'pairs' => true],
                ['mode' => 'adaptive', 'untilRound' => 3, 'aggressive' => ['advance' => 3.0, 'portalGuard' => 0.5]],
            ),
        ];
    }

    public static function get(string $id): ?Personality
    {
        return self::all()[$id] ?? null;
    }

    /** @return list<array{id:string,name:string,difficulty:string,pitch:string}> */
    public static function catalogue(): array
    {
        return array_values(array_map(
            static fn (Personality $p): array => ['id' => $p->id, 'name' => $p->name, 'difficulty' => $p->difficulty, 'pitch' => $p->pitch],
            self::all(),
        ));
    }
}
