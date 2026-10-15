<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\RandomSource;

/** Saves also work after the mushroom has died; its unit id remains the poison source. */
final class Spores
{
    public static function release(array $state, string $sourceId, string $hex, array $targets, string $ability, int $threshold, RandomSource $random, array &$events): array
    {
        $events[] = MatchEngine::event('sporesReleased', [$hex], ['unitId' => $sourceId, 'ability' => $ability, 'targetUnitIds' => $targets]);
        sort($targets, SORT_STRING);
        foreach ($targets as $id) {
            $roll = $random->roll(20);
            $saved = $roll >= $threshold;
            $events[] = MatchEngine::event('saveRolled', [$state['units'][$id]['hex']], [
                'unitId' => $id, 'sourceUnitId' => $sourceId, 'ability' => $ability, 'roll' => $roll, 'saved' => $saved,
            ]);
            if (!$saved) {
                $state = Poison::apply($state, $id, $sourceId, $random, $events);
            }
        }
        return $state;
    }

    public static function enemiesWithin(array $state, string $sourceId, string $hex, int $radius): array
    {
        return array_values(array_filter(array_map('strval', array_keys($state['units'])), static fn(string $id): bool =>
            $state['units'][$id]['hex'] !== null && Sides::enemies($state, $sourceId, $id)
            && Hex::distance($hex, $state['units'][$id]['hex']) <= $radius
        ));
    }
}
