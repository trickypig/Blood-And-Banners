<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

/** Voluntary movement treats water like open ground. */
final class AquaticSurge extends Ability
{
    public function ignoresTerrain(string $terrain, string $movementKind): bool
    {
        return $terrain === 'water' && $movementKind === 'voluntary';
    }
}
