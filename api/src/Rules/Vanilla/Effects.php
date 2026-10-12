<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

/** Timed stat changes. An effect expires when the named seat's turn starts (UNTIL_NEXT_TURN) or ends (UNTIL_END_OF_TURN). */
final class Effects
{
    public const UNTIL_NEXT_TURN = 'turnStart';
    public const UNTIL_END_OF_TURN = 'turnEnd';

    public static function add(
        array $state,
        string $name,
        string $sourceUnitId,
        string $targetUnitId,
        string $stat,
        int $amount,
        string $seatId,
        string $at,
        array &$events,
    ): array {
        $state['effects'] = array_values(array_filter(
            $state['effects'],
            static fn(array $effect): bool => !($effect['name'] === $name && $effect['targetUnitId'] === $targetUnitId)
        ));
        $effect = [
            'name' => $name,
            'sourceUnitId' => $sourceUnitId,
            'targetUnitId' => $targetUnitId,
            'stat' => $stat,
            'amount' => $amount,
            'expires' => ['seatId' => $seatId, 'at' => $at],
        ];
        $state['effects'][] = $effect;
        $hex = $state['units'][$targetUnitId]['hex'];
        $events[] = MatchEngine::event('effectApplied', $hex === null ? [] : [$hex], $effect);
        return $state;
    }

    public static function total(array $state, string $unitId, string $stat): int
    {
        $total = 0;
        foreach (self::forUnit($state, $unitId) as $effect) {
            if ($effect['stat'] === $stat) {
                $total += (int)$effect['amount'];
            }
        }
        return $total;
    }

    public static function forUnit(array $state, string $unitId): array
    {
        return array_values(array_filter(
            $state['effects'] ?? [],
            static fn(array $effect): bool => $effect['targetUnitId'] === $unitId
        ));
    }

    public static function expire(array $state, string $seatId, string $at, array &$events): array
    {
        $kept = [];
        foreach ($state['effects'] as $effect) {
            if ($effect['expires']['seatId'] === $seatId && $effect['expires']['at'] === $at) {
                $events[] = MatchEngine::event('effectExpired', [], ['name' => $effect['name'], 'targetUnitId' => $effect['targetUnitId']]);
                continue;
            }
            $kept[] = $effect;
        }
        $state['effects'] = $kept;
        return $state;
    }
}
