<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

final class Turn
{
    /** Per-turn bookkeeping that every fresh turn resets. */
    private const USAGE = ['attacks' => 0, 'moveSpent' => 0, 'movedAfterAttack' => false, 'abilitiesUsed' => [], 'glareUsed' => []];

    public static function idle(): array
    {
        return ['round' => 0, 'number' => 0, 'activeSeatId' => null, 'activeUnitId' => null, 'activated' => [], 'moved' => false, 'allowance' => 0] + self::USAGE;
    }

    public static function fresh(array $state, string $seatId, int $round): array
    {
        $alive = count(array_filter(
            $state['units'],
            static fn(array $unit): bool => $unit['seatId'] === $seatId && $unit['hex'] !== null
        ));
        return [
            'round' => $round,
            'number' => (int)($state['turn']['number'] ?? 0) + 1,
            'activeSeatId' => $seatId,
            'activeUnitId' => null,
            'activated' => [],
            'moved' => false,
            'allowance' => min(4, $alive),
        ] + self::USAGE;
    }

    /** Fill fields that turns saved before abilities existed are missing. */
    public static function normalize(array $turn): array
    {
        return $turn + ['number' => 1] + self::USAGE;
    }

    /** The turn number on which the given seat next starts a turn. */
    public static function nextTurnNumberOf(array $state, string $seatId): int
    {
        return $state['turn']['number'] + ($state['turn']['activeSeatId'] === $seatId ? 2 : 1);
    }

    public static function activationsRemaining(array $state): int
    {
        if ($state['phase'] !== 'active') {
            return 0;
        }
        return max(0, $state['turn']['allowance'] - count($state['turn']['activated']));
    }
}
