<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\Sides;

final class Submerged extends Ability
{
    public const FAMILY = ['Kraken', 'Kraken Tentacle (Lesser)', 'Kraken Tentacle (Greater)'];

    public function blocksAttack(array $state, string $ownerId, string $attackerId, string $originHex): bool
    {
        if (Hex::distance($originHex, $state['units'][$ownerId]['hex']) <= 1) {
            return false;
        }
        foreach ($state['units'] as $id => $unit) {
            if ($unit['hex'] === null || !Sides::allies($state, $ownerId, (string)$id) || !in_array($unit['name'], self::FAMILY, true)) {
                continue;
            }
            foreach ($state['units'] as $enemyId => $enemy) {
                if ($enemy['hex'] !== null && Sides::enemies($state, (string)$id, (string)$enemyId) && Hex::distance($unit['hex'], $enemy['hex']) === 1) {
                    return false;
                }
            }
        }
        return true;
    }
}
