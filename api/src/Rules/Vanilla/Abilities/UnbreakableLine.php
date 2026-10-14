<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\Sides;

/** Left Guard and Right Guard: +1 Defense while another member of the line (Left Guard, Center, Right Guard) is adjacent. */
final class UnbreakableLine extends Ability
{
    private const LINE = ['Left Guard', 'Center', 'Right Guard'];

    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        if ($stat !== 'defense' || $unitId !== $ownerId) {
            return 0;
        }
        $self = $state['units'][$ownerId];
        foreach ($state['units'] as $id => $unit) {
            if ((string)$id === $ownerId || $unit['hex'] === null || Sides::enemies($state, $ownerId, (string)$id)) {
                continue;
            }
            if (in_array($unit['name'], self::LINE, true) && $unit['name'] !== $self['name'] && Hex::distance($self['hex'], $unit['hex']) === 1) {
                return 1;
            }
        }
        return 0;
    }
}
