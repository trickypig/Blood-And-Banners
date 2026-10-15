<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\Sides;

final class OgreRivalry extends Ability
{
    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        if ($stat !== 'power' || $ownerId !== $unitId) {
            return 0;
        }
        foreach ($state['units'] as $id => $unit) {
            if ((string)$id !== $ownerId && $unit['hex'] !== null && $unit['name'] === 'Ogre'
                && Sides::allies($state, $ownerId, (string)$id)
                && Hex::distance($state['units'][$ownerId]['hex'], $unit['hex']) === 1) {
                return -1;
            }
        }
        return 0;
    }
}
