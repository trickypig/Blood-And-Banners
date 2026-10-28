<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\Vanilla\Modes\Modes;

final class Turn
{
    /** Per-turn bookkeeping that every fresh turn resets. */
    private const USAGE = ['attacks' => 0, 'specialAttackUsed' => false, 'moveSpent' => 0, 'movedAfterAttack' => false, 'startHex' => null, 'abilitiesUsed' => [], 'glareUsed' => [], 'itemsUsed' => []];

    public static function idle(): array
    {
        return ['round' => 0, 'number' => 0, 'slot' => 0, 'activeSeatId' => null, 'activeUnitId' => null, 'activated' => [], 'moved' => false, 'allowance' => 0] + self::USAGE;
    }

    public static function fresh(array $state, string $seatId, int $round, ?int $slot = null): array
    {
        return [
            'round' => $round,
            'number' => (int)($state['turn']['number'] ?? 0) + 1,
            'slot' => $slot ?? Schedule::slotOf($state, $seatId),
            'activeSeatId' => $seatId,
            'activeUnitId' => null,
            'activated' => [],
            'moved' => false,
            'allowance' => Modes::ofState($state)->allowance($state, $seatId),
        ] + self::USAGE;
    }

    /** Fill fields that turns saved before abilities and slots existed are missing. */
    public static function normalize(array $turn): array
    {
        return $turn + ['number' => 1, 'slot' => null] + self::USAGE;
    }

    /** The turn number on which the given seat next starts a turn. */
    public static function nextTurnNumberOf(array $state, string $seatId): int
    {
        return Schedule::nextTurnNumberOf($state, $seatId);
    }

    public static function activationsRemaining(array $state): int
    {
        if ($state['phase'] !== 'active') {
            return 0;
        }
        return max(0, $state['turn']['allowance'] - count($state['turn']['activated']));
    }
}
