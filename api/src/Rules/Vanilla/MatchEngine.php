<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\InitiativeResolver;
use BloodAndBanners\Rules\RandomSource;

final class MatchEngine
{
    public const UNITS_PER_SIDE = 7;

    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function catalog(): Catalog
    {
        return $this->catalog;
    }

    public function start(array $seatIds, array $board, RandomSource $random): array
    {
        $seatIds = array_values($seatIds);
        if (count($seatIds) !== 2) {
            throw new GameServiceException('modeNotPlayable', 'A Vanilla duel needs exactly two commanders.');
        }
        Placement::assertCapacity($board, self::UNITS_PER_SIDE);
        $initiative = (new InitiativeResolver())->resolve($seatIds, $random);
        $undecided = array_fill_keys($seatIds, null);
        $state = [
            'phase' => 'draft',
            'seats' => $seatIds,
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
            'pending' => null,
            'turn' => Turn::idle(),
            'result' => null,
        ];
        return [
            'state' => $state,
            'events' => [self::event('initiativeRolled', [], [
                'rolls' => $initiative['rolls'],
                'order' => $initiative['order'],
            ])],
        ];
    }

    public function apply(array $state, string $seatId, string $kind, array $payload, RandomSource $random): array
    {
        $state = self::normalize($state);
        return match ($state['phase']) {
            'draft' => (new DraftRules($this->catalog))->apply($state, $seatId, $kind, $payload),
            'active' => (new BattleRules())->apply($state, $seatId, $kind, $payload, $random),
            default => throw new GameServiceException('matchCompleted', 'The match is already over.'),
        };
    }

    public function legalActions(array $state, ?string $seatId): array
    {
        if ($seatId === null) {
            return [];
        }
        $state = self::normalize($state);
        return match ($state['phase']) {
            'draft' => (new DraftRules($this->catalog))->legalActions($state, $seatId),
            'active' => (new BattleRules())->legalActions($state, $seatId),
            default => [],
        };
    }

    /** Fill fields that states saved before abilities existed are missing, so old matches play on stats alone. */
    public static function normalize(array $state): array
    {
        $state['effects'] ??= [];
        $state['pending'] ??= null;
        $state['turn'] = Turn::normalize($state['turn'] ?? Turn::idle());
        foreach ($state['units'] ?? [] as $unitId => $unit) {
            $state['units'][$unitId] = $unit + ['abilities' => [], 'flags' => []];
        }
        return $state;
    }

    public static function event(string $kind, array $focusHexes, array $payload): array
    {
        return ['kind' => $kind, 'focusHexes' => array_values($focusHexes), 'payload' => $payload];
    }

    public static function otherSeat(array $state, string $seatId): string
    {
        return $state['seats'][0] === $seatId ? $state['seats'][1] : $state['seats'][0];
    }
}
