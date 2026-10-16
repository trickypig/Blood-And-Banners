<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\Sides;

/** Immune to attacks from more than one hex away while no enemy is adjacent. */
final class Burrow extends Ability
{
    public function blocksAttack(array $state, string $ownerId, string $attackerId, string $originHex): bool
    {
        $here = $state['units'][$ownerId]['hex'];
        if (Hex::distance($originHex, $here) <= 1) {
            return false;
        }
        foreach ($state['units'] as $id => $unit) {
            if ($unit['hex'] !== null && Sides::enemies($state, $ownerId, (string)$id) && Hex::distance($here, $unit['hex']) === 1) {
                return false;
            }
        }
        return true;
    }

    public function blockedAttackError(): string
    {
        return 'targetBurrowed';
    }
}
