<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

final class Fireball extends SpecialAttack
{
    public function targets(array $state, string $unitId): array
    {
        if ($state['units'][$unitId]['name'] === 'Pyromancer') {
            return (new PyromancerFireball($this->name))->targets($state, $unitId);
        }
        return Targeting::enemiesInSight($state, $unitId, 4);
    }

    public function selection(array $state, string $unitId): ?array
    {
        return $state['units'][$unitId]['name'] === 'Pyromancer' ? (new PyromancerFireball($this->name))->selection($state, $unitId) : null;
    }

    public function validate(array $state, string $unitId, array $targetIds, array $options = []): ?string
    {
        return $state['units'][$unitId]['name'] === 'Pyromancer'
            ? (new PyromancerFireball($this->name))->validate($state, $unitId, $targetIds, $options)
            : parent::validate($state, $unitId, $targetIds, $options);
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        if ($state['units'][$unitId]['name'] === 'Pyromancer') {
            return (new PyromancerFireball($this->name))->steps($state, $unitId, $targetIds, $options);
        }
        $target = $targetIds[0];
        return [
            StepRunner::strike($unitId, $target, 4, $this->name, false, 'fire'),
            StepRunner::splash($state['units'][$target]['hex'], $target, $this->name, $unitId, $state['units'][$unitId]['hex']),
        ];
    }
}
