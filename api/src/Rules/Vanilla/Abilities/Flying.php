<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

final class Flying extends Ability
{
    public function ignoresTerrain(string $terrain, string $movementKind): bool
    {
        return in_array($terrain, ['water', 'ice'], true)
            || ($terrain === 'lava' && $movementKind === 'voluntary');
    }
}
