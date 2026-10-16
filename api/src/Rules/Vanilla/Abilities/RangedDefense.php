<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Hex;

/** Stoneskin and Hollow Bones: +2 Defense against an attack from more than one hex away. */
final class RangedDefense extends Ability
{
    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        if ($stat !== 'defense' || $ownerId !== $unitId || ($context['defenderId'] ?? null) !== $ownerId) {
            return 0;
        }
        $attacker = $state['units'][$context['attackerId'] ?? ''] ?? null;
        $origin = $context['originHex'] ?? $attacker['hex'] ?? null;
        return $attacker !== null && $origin !== null
            && Hex::distance($origin, $state['units'][$ownerId]['hex']) > 1 ? 2 : 0;
    }
}
