<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

final class Turn
{
    public static function idle(): array
    {
        return ['round' => 0, 'activeSeatId' => null, 'activeUnitId' => null, 'activated' => [], 'moved' => false, 'allowance' => 0];
    }

    public static function fresh(array $state, string $seatId, int $round): array
    {
        $alive = count(array_filter(
            $state['units'],
            static fn(array $unit): bool => $unit['seatId'] === $seatId && $unit['hex'] !== null
        ));
        return [
            'round' => $round,
            'activeSeatId' => $seatId,
            'activeUnitId' => null,
            'activated' => [],
            'moved' => false,
            'allowance' => min(4, $alive),
        ];
    }

    public static function activationsRemaining(array $state): int
    {
        if ($state['phase'] !== 'active') {
            return 0;
        }
        return max(0, $state['turn']['allowance'] - count($state['turn']['activated']));
    }
}
