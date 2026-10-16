<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Targeting;

final class DragonBreath extends SpecialAttack
{
    public function targets(array $state, string $unitId): array
    {
        return Targeting::enemiesInSight($state, $unitId, 4);
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        return [['op' => 'dragonBreath', 'attackerId' => $unitId, 'targetId' => $targetIds[0], 'ability' => $this->name]];
    }
}
