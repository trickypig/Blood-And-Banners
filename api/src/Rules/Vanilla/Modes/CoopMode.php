<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Modes;

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\InitiativeResolver;
use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Board;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Placement;
use BloodAndBanners\Rules\Vanilla\Sides;
use BloodAndBanners\Rules\Vanilla\Turn;
use BloodAndBanners\Rules\Vanilla\Wounds;
use LogicException;

/** One human Void Lord against 1–4 hero players who share a start. */
final class CoopMode implements ModeRules
{
    public const UNITS_PER_HERO_PLAYER = 8;
    private const MESSAGES = [
        'voidLordRequired' => 'A Co-op match needs a Void Lord.',
        'heroPlayerRequired' => 'A Co-op match needs at least one hero player.',
        'invalidCoopScenario' => 'This battlefield is not set up for Co-op.',
    ];

    public function __construct(private readonly ?Catalog $catalog = null)
    {
    }

    public function start(array $seatIds, array $board, RandomSource $random, array $options): array
    {
        $voidLord = $options['voidLordSeatId'] ?? null;
        if (!is_string($voidLord) || !in_array($voidLord, $seatIds, true)) {
            self::fail('voidLordRequired');
        }
        $heroSeats = array_values(array_diff($seatIds, [$voidLord]));
        if ($heroSeats === []) {
            self::fail('heroPlayerRequired');
        }
        $layout = self::layout($board);
        $probe = array_map(static fn(int $i): string => "probe-{$i}", range(1, count($heroSeats) * self::UNITS_PER_HERO_PLAYER));
        Placement::place($board, $layout['start'], $layout['facing'], $probe, array_fill_keys($layout['spawn'], true));

        $initiative = (new InitiativeResolver())->resolve($heroSeats, $random);
        $order = $initiative['order'];
        $budget = self::budget($options['coop'] ?? [], count($heroSeats));
        $lobby = $options['setupState'] ?? null;
        if (!is_array($lobby) || ($lobby['voidLordSeatId'] ?? null) !== $voidLord) {
            $lobby = null;
        }
        $state = [
            'mode' => 'coop',
            'phase' => 'prep',
            'seats' => $seatIds,
            'voidLordSeatId' => $voidLord,
            'sides' => [
                'heroes' => ['kind' => 'heroes', 'seatIds' => $order],
                'void' => ['kind' => 'void', 'seatIds' => [$voidLord]],
            ],
            'board' => $board,
            'initiative' => ['rolls' => $initiative['rolls'], 'order' => $order],
            'setup' => [
                'budget' => $budget,
                'spent' => (int)($lobby['setup']['spent'] ?? 0),
                'nextMonster' => (int)($lobby['setup']['nextMonster'] ?? 1),
                'confirmed' => false,
            ],
            'draft' => [
                'pickingSeatId' => $order[0],
                'pickIndex' => 0,
                'firstSeatId' => null,
                'heroesBySeat' => array_fill_keys($order, []),
                'armyBySeat' => array_fill_keys($order, null),
            ],
            'units' => $lobby['units'] ?? [],
            'effects' => $lobby['effects'] ?? [],
            'conditions' => $lobby['conditions'] ?? [],
            'pending' => null,
            'turn' => Turn::idle(),
            'result' => null,
        ];
        return [
            'state' => $state,
            'events' => [MatchEngine::event('initiativeRolled', [], ['rolls' => $initiative['rolls'], 'order' => $order])],
        ];
    }

    public function applyPreBattle(array $state, string $seatId, string $kind, array $payload): array
    {
        return match ($state['phase']) {
            'setup' => (new CoopSetup($this->catalog()))->apply($state, $seatId, $kind, $payload),
            'prep' => (new CoopPrep($this->catalog()))->apply($state, $seatId, $kind, $payload),
            default => (new CoopDraft($this->catalog()))->apply($state, $seatId, $kind, $payload),
        };
    }

    public function legalPreBattle(array $state, string $seatId): array
    {
        return match ($state['phase']) {
            'setup' => (new CoopSetup($this->catalog()))->legalActions($state, $seatId),
            'prep' => (new CoopPrep($this->catalog()))->legalActions($state, $seatId),
            default => (new CoopDraft($this->catalog()))->legalActions($state, $seatId),
        };
    }

    public function slotCount(array $state): int
    {
        return 2 * count($state['initiative']['order']);
    }

    public function seatAtSlot(array $state, int $slot): string
    {
        return $slot % 2 === 0 ? $state['initiative']['order'][intdiv($slot, 2)] : $state['voidLordSeatId'];
    }

    public function slotLive(array $state, int $slot): bool
    {
        return Wounds::anyAlive($state, $state['initiative']['order'][intdiv($slot, 2)]);
    }

    public function allowance(array $state, string $seatId): int
    {
        $alive = count(array_filter(
            $state['units'],
            static fn(array $unit): bool => $unit['seatId'] === $seatId && $unit['hex'] !== null
        ));
        return min(5, $alive);
    }

    public function activationError(array $state, string $unitId): ?string
    {
        $unit = $state['units'][$unitId];
        if ($unit['seatId'] === $state['voidLordSeatId'] || $unit['isHero']) {
            return null;
        }
        $fallen = count(array_filter(
            $state['units'],
            static fn(array $other): bool => $other['seatId'] === $unit['seatId'] && $other['isHero'] && $other['hex'] === null
        ));
        $others = count(array_filter($state['turn']['activated'], static fn(string $id): bool => !$state['units'][$id]['isHero']));
        return $others >= 3 + $fallen ? 'heroesActivationRequired' : null;
    }

    public function victory(array $state): ?array
    {
        if (!Sides::anyAlive($state, 'void')) {
            return ['reason' => 'monstersDefeated', 'winningSeatId' => null, 'winningSide' => 'heroes'];
        }
        foreach ($state['units'] as $unitId => $unit) {
            if ($unit['isHero'] && $unit['hex'] !== null && Sides::ofUnit($state, (string)$unitId) === 'heroes') {
                return null;
            }
        }
        return ['reason' => 'heroesDefeated', 'winningSeatId' => $state['voidLordSeatId'], 'winningSide' => 'void'];
    }

    /** The shared hero start, the spawn hex the heroes face, and every spawn hex. */
    public static function layout(array $board): array
    {
        $homes = Board::homes($board);
        $spawn = [];
        foreach ($board as $key => $hex) {
            if (($hex['spawnZone'] ?? null) === 'void') {
                $spawn[] = (string)$key;
            }
        }
        if (count($homes) !== 1 || $spawn === []) {
            self::fail('invalidCoopScenario');
        }
        $start = array_values($homes)[0];
        $facing = $spawn;
        usort($facing, static fn(string $a, string $b): int => [Hex::distance($start, $a), $a] <=> [Hex::distance($start, $b), $b]);
        sort($spawn, SORT_STRING);
        return ['start' => $start, 'facing' => $facing[0], 'spawn' => $spawn];
    }

    public const MAX_HERO_PLAYERS = 4;

    /** Tier points for the Void Lord: base points plus a share per hero player. */
    public static function budget(array $rules, int $heroPlayers): int
    {
        return (int)($rules['basePoints'] ?? 4) + (int)($rules['pointsPerHeroPlayer'] ?? 8) * $heroPlayers;
    }

    /** The Void Lord's board while the war room is still open: no hero seats, initiative or draft yet. */
    public static function lobbySetup(array $board, string $voidLordSeatId, int $budget): array
    {
        self::layout($board);
        return [
            'mode' => 'coop',
            'phase' => 'setup',
            'seats' => [$voidLordSeatId],
            'voidLordSeatId' => $voidLordSeatId,
            'sides' => [
                'heroes' => ['kind' => 'heroes', 'seatIds' => []],
                'void' => ['kind' => 'void', 'seatIds' => [$voidLordSeatId]],
            ],
            'board' => $board,
            'initiative' => ['rolls' => [], 'order' => []],
            'setup' => ['budget' => $budget, 'spent' => 0, 'nextMonster' => 1, 'confirmed' => false],
            'draft' => ['pickingSeatId' => null, 'pickIndex' => 0, 'firstSeatId' => null, 'heroesBySeat' => [], 'armyBySeat' => []],
            'units' => [],
            'effects' => [],
            'conditions' => [],
            'pending' => null,
            'turn' => Turn::idle(),
            'result' => null,
        ];
    }

    /** The lobby horde follows the Void Lord role to its new seat. */
    public static function handOver(array $state, string $voidLordSeatId): array
    {
        foreach ($state['units'] as $unitId => $unit) {
            $state['units'][$unitId]['seatId'] = $voidLordSeatId;
        }
        $state['voidLordSeatId'] = $voidLordSeatId;
        $state['seats'] = [$voidLordSeatId];
        $state['sides']['void']['seatIds'] = [$voidLordSeatId];
        return $state;
    }

    private function catalog(): Catalog
    {
        if ($this->catalog === null) {
            throw new LogicException('Co-op setup and draft need the catalog.');
        }
        return $this->catalog;
    }

    private static function fail(string $code): never
    {
        throw new GameServiceException($code, self::MESSAGES[$code]);
    }
}
