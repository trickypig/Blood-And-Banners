<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Game\GameServiceException;
use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Modes\Modes;
use BloodAndBanners\Rules\Vanilla\Modes\VanillaMode;

final class MatchEngine
{
    public const UNITS_PER_SIDE = VanillaMode::UNITS_PER_SIDE;

    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function catalog(): Catalog
    {
        return $this->catalog;
    }

    public function start(array $seatIds, array $board, RandomSource $random, string $mode = 'vanillaPvp', array $options = []): array
    {
        return Modes::for($mode, $this->catalog)->start(array_values($seatIds), $board, $random, $options);
    }

    public function apply(array $state, string $seatId, string $kind, array $payload, RandomSource $random, ?int $now = null): array
    {
        $state = self::normalize($state);
        return match ($state['phase']) {
            'setup', 'draft' => Modes::ofState($state, $this->catalog)->applyPreBattle($state, $seatId, $kind, $payload),
            'active' => (new BattleRules($now ?? time()))->apply($state, $seatId, $kind, $payload, $random),
            default => throw new GameServiceException('matchCompleted', 'The match is already over.'),
        };
    }

    public function legalActions(array $state, ?string $seatId, ?int $now = null): array
    {
        if ($seatId === null) {
            return [];
        }
        $state = self::normalize($state);
        return match ($state['phase']) {
            'setup', 'draft' => Modes::ofState($state, $this->catalog)->legalPreBattle($state, $seatId),
            'active' => (new BattleRules($now ?? time()))->legalActions($state, $seatId),
            default => [],
        };
    }

    /** Fill fields that states saved before abilities existed are missing, so old matches play on stats alone. */
    public static function normalize(array $state): array
    {
        $state['mode'] ??= 'vanillaPvp';
        $state['effects'] ??= [];
        $state['conditions'] ??= [];
        $state['pending'] ??= null;
        $state['sides'] ??= Sides::perSeat($state['seats'] ?? []);
        $state['turn'] = Turn::normalize($state['turn'] ?? Turn::idle());
        if ($state['turn']['slot'] === null) {
            $state['turn']['slot'] = $state['turn']['activeSeatId'] === null ? 0 : Schedule::slotOf($state, $state['turn']['activeSeatId']);
        }
        foreach ($state['board'] ?? [] as $key => $hex) {
            $state['board'][$key] = $hex + ['biome' => 'plains', 'terrain' => 'grass'];
        }
        foreach ($state['units'] ?? [] as $unitId => $unit) {
            $state['units'][$unitId] = $unit + ['abilities' => [], 'flags' => [], 'side' => $unit['seatId']];
        }
        return $state;
    }

    public static function event(string $kind, array $focusHexes, array $payload): array
    {
        return ['kind' => $kind, 'focusHexes' => array_values($focusHexes), 'payload' => $payload];
    }
}
