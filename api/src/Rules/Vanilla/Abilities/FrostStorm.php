<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

final class FrostStorm extends SpecialAttack
{
    public function unavailable(array $state, string $unitId): ?string
    {
        return ($state['units'][$unitId]['flags']['frostStormCooldown'] ?? 0) > 0 ? 'abilityCoolingDown' : null;
    }

    public function onUse(array $state, string $unitId): array
    {
        // End of this owning turn, then end of the next owning turn. Hero turns do not count.
        $state['units'][$unitId]['flags']['frostStormCooldown'] = 2;
        return $state;
    }

    public function afterTurn(array $state, string $unitId): array
    {
        $remaining = $state['units'][$unitId]['flags']['frostStormCooldown'] ?? 0;
        if ($remaining > 0) {
            $state['units'][$unitId]['flags']['frostStormCooldown'] = $remaining - 1;
        }
        return $state;
    }

    public function targets(array $state, string $unitId): array
    {
        return $this->unavailable($state, $unitId) === null ? Targeting::enemiesInSight($state, $unitId, 3) : [];
    }

    public function selection(array $state, string $unitId): ?array
    {
        return ['mode' => 'independent', 'maxTargets' => 3];
    }

    public function validate(array $state, string $unitId, array $targetIds, array $options = []): ?string
    {
        return count($targetIds) >= 1 && count($targetIds) <= 3 && count(array_unique($targetIds)) === count($targetIds)
            && array_diff($targetIds, $this->targets($state, $unitId)) === [] ? null : 'invalidAbilityTarget';
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        $power = 6 - count($targetIds);
        return array_map(fn(string $id): array => StepRunner::strike($unitId, $id, $power, $this->name), $targetIds);
    }
}
