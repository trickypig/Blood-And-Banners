<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

final class Fireball extends SpecialAttack
{
    public function targets(array $state, string $unitId): array
    {
        return Targeting::enemiesInSight($state, $unitId, 4);
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        $target = $targetIds[0];
        return [
            StepRunner::strike($unitId, $target, 4, $this->name, false, 'fire'),
            StepRunner::splash($state['units'][$target]['hex'], $target, $this->name),
        ];
    }
}
