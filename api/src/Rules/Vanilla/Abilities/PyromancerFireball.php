<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Combat;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\Sides;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

/** The creature's printed Fireball, dispatched by the shared name handler. */
final class PyromancerFireball extends SpecialAttack
{
    public function targets(array $state, string $unitId): array
    {
        return Targeting::enemiesInSight($state, $unitId, Stats::effective($state, $unitId, 'range'));
    }

    private function secondaries(array $state, string $unitId, string $primaryId): array
    {
        $ids = array_values(array_filter(array_map('strval', array_keys($state['units'])), static fn(string $id): bool =>
            $id !== $primaryId && $state['units'][$id]['hex'] !== null && Sides::enemies($state, $unitId, $id)
            && Hex::distance($state['units'][$primaryId]['hex'], $state['units'][$id]['hex']) === 1
            && !Combat::protectedFrom($state, $unitId, $id)
        ));
        sort($ids, SORT_STRING);
        return $ids;
    }

    public function selection(array $state, string $unitId): ?array
    {
        $secondaryTargets = [];
        foreach ($this->targets($state, $unitId) as $id) {
            $secondaryTargets[$state['units'][$id]['hex']] = array_map(static fn(string $id): string => $state['units'][$id]['hex'], $this->secondaries($state, $unitId, $id));
        }
        return ['mode' => 'adjacentEnemies', 'maxTargets' => 3, 'secondaryTargets' => (object)$secondaryTargets];
    }

    public function validate(array $state, string $unitId, array $targetIds, array $options = []): ?string
    {
        if (count($targetIds) < 1 || count($targetIds) > 3 || count(array_unique($targetIds)) !== count($targetIds)
            || !in_array($targetIds[0], $this->targets($state, $unitId), true)) {
            return 'invalidAbilityTarget';
        }
        return array_diff(array_slice($targetIds, 1), $this->secondaries($state, $unitId, $targetIds[0])) === [] ? null : 'invalidAbilityTarget';
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        return [
            StepRunner::strike($unitId, $targetIds[0], null, $this->name, false, 'fire'),
            ['op' => 'selectedSplash', 'sourceUnitId' => $unitId, 'targetIds' => array_slice($targetIds, 1),
                'hex' => $state['units'][$targetIds[0]]['hex'], 'ability' => $this->name, 'originHex' => $state['units'][$unitId]['hex']],
        ];
    }
}
