<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

final class ChaosStrike extends SpecialAttack
{
    public function targets(array $state, string $unitId): array
    {
        return Targeting::enemiesInSight($state, $unitId, Stats::effective($state, $unitId, 'range'));
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        return [StepRunner::chaos($unitId, $targetIds[0], $this->name)];
    }
}
