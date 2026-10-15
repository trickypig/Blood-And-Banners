<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

final class Outrage extends Ability
{
    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        $unit = $state['units'][$ownerId];
        if ($stat !== 'power' || $ownerId !== $unitId || $unit['hp'] - $unit['wounds'] !== 1) {
            return 0;
        }
        return match ($unit['name']) {
            'Marsh Hydra' => 2,
            'Void Hydra' => 1,
            default => 0,
        };
    }
}
