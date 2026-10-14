<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Modes;

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\InitiativeResolver;
use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\DraftRules;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Placement;
use BloodAndBanners\Rules\Vanilla\Sides;
use BloodAndBanners\Rules\Vanilla\Turn;
use BloodAndBanners\Rules\Vanilla\Wounds;
use LogicException;

final class VanillaMode implements ModeRules
{
    public const UNITS_PER_SIDE = 7;

    public function __construct(private readonly ?Catalog $catalog = null)
    {
    }

    public function start(array $seatIds, array $board, RandomSource $random, array $options): array
    {
        if (count($seatIds) !== 2) {
            throw new GameServiceException('modeNotPlayable', 'A Vanilla duel needs exactly two commanders.');
        }
        Placement::assertCapacity($board, self::UNITS_PER_SIDE);
        $initiative = (new InitiativeResolver())->resolve($seatIds, $random);
        $undecided = array_fill_keys($seatIds, null);
        $state = [
            'mode' => 'vanillaPvp',
            'phase' => 'draft',
            'seats' => $seatIds,
            'sides' => Sides::perSeat($seatIds),
            'board' => $board,
            'initiative' => ['rolls' => $initiative['rolls'], 'order' => $initiative['order']],
            'draft' => [
                'pickingSeatId' => $initiative['order'][0],
                'pickIndex' => 0,
                'firstSeatId' => null,
                'startBySeat' => $undecided,
                'heroBySeat' => $undecided,
                'armyBySeat' => $undecided,
            ],
            'units' => [],
            'effects' => [],
            'conditions' => [],
            'pending' => null,
            'turn' => Turn::idle(),
            'result' => null,
        ];
        return [
            'state' => $state,
            'events' => [MatchEngine::event('initiativeRolled', [], [
                'rolls' => $initiative['rolls'],
                'order' => $initiative['order'],
            ])],
        ];
    }

    public function applyPreBattle(array $state, string $seatId, string $kind, array $payload): array
    {
        return $this->draft()->apply($state, $seatId, $kind, $payload);
    }

    public function legalPreBattle(array $state, string $seatId): array
    {
        return $this->draft()->legalActions($state, $seatId);
    }

    public function slotCount(array $state): int
    {
        return 2;
    }

    public function seatAtSlot(array $state, int $slot): string
    {
        $first = $state['draft']['firstSeatId'] ?? $state['seats'][0];
        return $slot % 2 === 0 ? $first : self::other($state, $first);
    }

    public function slotLive(array $state, int $slot): bool
    {
        return true;
    }

    public function allowance(array $state, string $seatId): int
    {
        return min(4, self::alive($state, $seatId));
    }

    public function activationError(array $state, string $unitId): ?string
    {
        $unit = $state['units'][$unitId];
        if ($unit['isHero'] || !self::heroAlive($state, $unit['seatId'])) {
            return null;
        }
        $others = count(array_filter($state['turn']['activated'], static fn(string $id): bool => !$state['units'][$id]['isHero']));
        return $others >= 3 ? 'heroActivationRequired' : null;
    }

    public function victory(array $state): ?array
    {
        foreach ($state['seats'] as $seatId) {
            if (!Wounds::anyAlive($state, $seatId)) {
                return ['reason' => 'annihilation', 'winningSeatId' => self::other($state, $seatId)];
            }
        }
        return null;
    }

    public static function other(array $state, string $seatId): string
    {
        return $state['seats'][0] === $seatId ? $state['seats'][1] : $state['seats'][0];
    }

    private function draft(): DraftRules
    {
        if ($this->catalog === null) {
            throw new LogicException('The draft needs the catalog.');
        }
        return new DraftRules($this->catalog);
    }

    private static function alive(array $state, string $seatId): int
    {
        return count(array_filter(
            $state['units'],
            static fn(array $unit): bool => $unit['seatId'] === $seatId && $unit['hex'] !== null
        ));
    }

    private static function heroAlive(array $state, string $seatId): bool
    {
        foreach ($state['units'] as $unit) {
            if ($unit['seatId'] === $seatId && $unit['isHero'] && $unit['hex'] !== null) {
                return true;
            }
        }
        return false;
    }
}
