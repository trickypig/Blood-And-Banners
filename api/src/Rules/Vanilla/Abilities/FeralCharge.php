<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Hex;

/** +2 Power when this activation has carried the owner closer to its target than where the activation began. */
final class FeralCharge extends Ability
{
    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        // A single attack closes the activation before its strike resolves, so key on the latest activation instead.
        $activated = $state['turn']['activated'] ?? [];
        if ($stat !== 'power' || $ownerId !== $unitId || ($context['attackerId'] ?? null) !== $ownerId
            || end($activated) !== $ownerId) {
            return 0;
        }
        $start = $state['turn']['startHex'] ?? null;
        $here = $state['units'][$ownerId]['hex'];
        $target = $state['units'][$context['defenderId'] ?? '']['hex'] ?? null;
        if ($start === null || $here === null || $target === null) {
            return 0;
        }
        return Hex::distance($here, $target) < Hex::distance($start, $target) ? 2 : 0;
    }
}
