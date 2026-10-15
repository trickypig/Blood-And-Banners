<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\Sides;

final class PackTactics extends Ability
{
    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        if ($stat !== 'power' || $ownerId !== $unitId) {
            return 0;
        }
        $bonus = 0;
        foreach ($state['units'] as $id => $unit) {
            if ((string)$id !== $ownerId && $unit['hex'] !== null
                && in_array($unit['name'], ['Ice Wolf', 'Dire Wolf'], true)
                && Sides::allies($state, $ownerId, (string)$id)
                && Hex::distance($state['units'][$ownerId]['hex'], $unit['hex']) <= 2) {
                if (++$bonus === 2) {
                    break;
                }
            }
        }
        return $bonus;
    }
}
