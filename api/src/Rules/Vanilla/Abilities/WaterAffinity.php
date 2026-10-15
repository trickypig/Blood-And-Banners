<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Water;

final class WaterAffinity extends Ability
{
    public function allowsVoluntaryHex(array $state, array $unit, string $hex): bool
    {
        return $unit['name'] === 'Sahuagin' || Water::nearby($state, $hex) !== [];
    }

    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        if ($ownerId !== $unitId) {
            return 0;
        }
        if ($state['units'][$ownerId]['name'] === 'Sahuagin') {
            return in_array($stat, ['move', 'power'], true) && !Water::contains($state, $state['units'][$ownerId]['hex']) ? -1 : 0;
        }
        return (new RangedDefense($this->name))->statDelta($state, $ownerId, $unitId, $stat, $context);
    }
}
