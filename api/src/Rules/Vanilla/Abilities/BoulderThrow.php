<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

final class BoulderThrow extends SpecialAttack
{
    public function targets(array $state, string $unitId): array
    {
        return Targeting::enemiesInSight($state, $unitId, 3);
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        return [StepRunner::strike($unitId, $targetIds[0], 3, $this->name)];
    }

    public function afterStrike(array $state, string $unitId, string $targetId, int $wounds): array
    {
        return [StepRunner::push($targetId, $unitId, 1)];
    }
}
